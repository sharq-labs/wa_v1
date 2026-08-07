<?php

namespace App\Services\Automation;

use App\Enums\NodeType;
use App\Models\AgentTeam;
use App\Models\Automation;
use App\Models\Tag;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * WhatsApp-style simulator for the flow builder.
 *
 * Interprets the draft definition against a virtual contact — nothing is
 * persisted to the CRM and no provider requests are made. Session state
 * lives in the cache so Ask Question flows work across requests.
 */
class SimulationService
{
    protected const TTL = 3600;

    public function __construct(
        protected ConditionEvaluatorForSimulation $conditions,
        protected VariableInterpolator $interpolator,
    ) {}

    public function start(Workspace $workspace, Automation $automation, array $definition): array
    {
        $sessionId = (string) Str::uuid();

        $state = [
            'session_id' => $sessionId,
            'workspace_id' => $workspace->id,
            'automation_id' => $automation->id,
            'definition' => $definition,
            'status' => 'idle', // idle | waiting_reply | completed | failed
            'current_node_id' => null,
            'waiting' => null,
            'steps' => 0,
            'contact' => [
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'phone_number' => '+201000000000',
            ],
            'custom_fields' => [],
            'tags' => [],
            'variables' => [],
            'transcript' => [],
            'log' => [],
            'conversation' => ['bot' => 'active', 'status' => 'open', 'assigned' => null],
        ];

        $this->save($state);

        return $this->publicState($state);
    }

    public function sendCustomerMessage(string $sessionId, string $text, ?string $replyId = null): array
    {
        $state = $this->load($sessionId);

        if (! $state) {
            return ['error' => 'Simulation session expired. Restart the test.'];
        }

        $state['transcript'][] = ['from' => 'customer', 'type' => 'text', 'text' => $text, 'at' => now()->toIso8601String()];

        if ($state['status'] === 'waiting_reply') {
            $state = $this->resumeFromWait($state, $text, $replyId);
        } else {
            $state = $this->startFromTrigger($state, $text);
        }

        $this->save($state);

        return $this->publicState($state);
    }

    protected function startFromTrigger(array $state, string $text): array
    {
        $definition = $state['definition'];
        $trigger = null;

        foreach ($definition['nodes'] ?? [] as $node) {
            $type = NodeType::tryFrom($node['type'] ?? '');
            if ($type?->isTrigger()) {
                $trigger = $node;
                break;
            }
        }

        if (! $trigger) {
            $state['log'][] = $this->logEntry(null, 'No trigger node in flow.');
            $state['status'] = 'failed';

            return $state;
        }

        $matches = $this->triggerMatches($trigger, $text);
        $state['log'][] = $this->logEntry($trigger['id'], $matches ? 'Trigger matched' : 'Trigger did not match');

        if (! $matches) {
            return $state;
        }

        $state['status'] = 'running';
        $state['last_message'] = $text;

        $next = $this->nextNode($definition, $trigger['id'], 'next');

        return $next ? $this->walk($state, $next) : $this->finish($state);
    }

    protected function triggerMatches(array $trigger, string $text): bool
    {
        $config = $trigger['config'] ?? [];
        $lower = mb_strtolower(trim($text));

        return match ($trigger['type']) {
            NodeType::TriggerKeyword->value => collect($config['keywords'] ?? [])
                ->map(fn ($k) => mb_strtolower(trim((string) $k)))
                ->filter()
                ->contains(function (string $keyword) use ($lower, $config) {
                    return match ($config['match_type'] ?? 'contains') {
                        'exact' => $lower === $keyword,
                        'starts_with' => str_starts_with($lower, $keyword),
                        'ends_with' => str_ends_with($lower, $keyword),
                        default => str_contains($lower, $keyword),
                    };
                }),
            default => true,
        };
    }

    protected function resumeFromWait(array $state, string $text, ?string $replyId): array
    {
        $waiting = $state['waiting'];
        $state['status'] = 'running';
        $state['waiting'] = null;
        $state['last_message'] = $text;

        $node = $this->findNode($state['definition'], $waiting['node_id']);

        if (! $node) {
            $state['status'] = 'failed';

            return $state;
        }

        if ($waiting['kind'] === 'question') {
            $valid = $this->validateAnswer($text, $waiting['validation'] ?? 'text', $waiting['choices'] ?? []);

            if (! $valid) {
                $error = $waiting['error_message'] ?? 'Sorry, that does not look valid. Please try again.';
                $state['transcript'][] = ['from' => 'bot', 'type' => 'text', 'text' => $error, 'at' => now()->toIso8601String()];
                $state['log'][] = $this->logEntry($node['id'], 'Invalid answer — still waiting');
                $state['status'] = 'waiting_reply';
                $state['waiting'] = $waiting;

                return $state;
            }

            $this->saveTo($state, $waiting['save_to'] ?? '', $text);
            $state['log'][] = $this->logEntry($node['id'], 'Answer saved to '.($waiting['save_to'] ?? '?'));

            $next = $this->nextNode($state['definition'], $node['id'], 'next');

            return $next ? $this->walk($state, $next) : $this->finish($state);
        }

        // Buttons wait
        $buttons = $waiting['buttons'] ?? [];
        $matched = null;

        foreach ($buttons as $button) {
            if (($replyId !== null && $button['id'] === $replyId)
                || mb_strtolower($button['title']) === mb_strtolower(trim($text))) {
                $matched = $button;
                break;
            }
        }

        if (! $matched) {
            $state['log'][] = $this->logEntry($node['id'], 'Reply did not match a button — still waiting');
            $state['status'] = 'waiting_reply';
            $state['waiting'] = $waiting;

            return $state;
        }

        $this->saveTo($state, $waiting['save_to'] ?? '', $matched['title']);
        $state['log'][] = $this->logEntry($node['id'], 'Button selected: '.$matched['title']);

        $next = $this->nextNode($state['definition'], $node['id'], $matched['id'])
            ?? $this->nextNode($state['definition'], $node['id'], 'next');

        return $next ? $this->walk($state, $next) : $this->finish($state);
    }

    /**
     * Walk nodes synchronously until a wait/stop/end.
     */
    protected function walk(array $state, string $nodeId): array
    {
        $definition = $state['definition'];
        $max = (int) config('whatsapp.max_automation_steps', 200);
        $current = $nodeId;

        while ($current !== null) {
            if (++$state['steps'] > $max) {
                $state['log'][] = $this->logEntry($current, "Step limit ({$max}) reached — stopping.");
                $state['status'] = 'failed';

                return $state;
            }

            $node = $this->findNode($definition, $current);

            if (! $node) {
                $state['log'][] = $this->logEntry($current, 'Node missing.');
                $state['status'] = 'failed';

                return $state;
            }

            $state['current_node_id'] = $current;
            [$state, $outcome, $handle] = $this->executeNode($state, $node);

            if ($outcome === 'wait') {
                $state['status'] = 'waiting_reply';

                return $state;
            }

            if ($outcome === 'stop') {
                return $this->finish($state);
            }

            if ($outcome === 'goto') {
                $current = $handle;

                continue;
            }

            $current = $this->nextNode($definition, $node['id'], $handle);
        }

        return $this->finish($state);
    }

    /**
     * @return array{0: array, 1: 'continue'|'wait'|'stop'|'goto', 2: string}
     */
    protected function executeNode(array $state, array $node): array
    {
        $config = $node['config'] ?? [];
        $resolver = $this->resolver($state);

        switch ($node['type']) {
            case NodeType::SendText->value:
                $text = $this->interpolator->interpolate((string) ($config['text'] ?? ''), $resolver);
                $state['transcript'][] = ['from' => 'bot', 'type' => 'text', 'text' => $text, 'at' => now()->toIso8601String()];
                $state['log'][] = $this->logEntry($node['id'], 'Sent message');

                return [$state, 'continue', 'next'];

            case NodeType::SendImage->value:
            case NodeType::SendVideo->value:
            case NodeType::SendAudio->value:
            case NodeType::SendDocument->value:
                $state['transcript'][] = [
                    'from' => 'bot',
                    'type' => str_replace('send_', '', $node['type']),
                    'text' => $config['caption'] ?? null,
                    'media_url' => $config['url'] ?? null,
                    'at' => now()->toIso8601String(),
                ];
                $state['log'][] = $this->logEntry($node['id'], 'Sent media');

                return [$state, 'continue', 'next'];

            case NodeType::SendTemplate->value:
                $template = isset($config['template_id'])
                    ? WhatsAppTemplate::query()->find($config['template_id'])
                    : null;
                $body = $template?->body ?? '[template]';
                $state['transcript'][] = ['from' => 'bot', 'type' => 'template', 'text' => $body, 'at' => now()->toIso8601String()];
                $state['log'][] = $this->logEntry($node['id'], 'Sent template '.($template?->name ?? '?'));

                return [$state, 'continue', 'next'];

            case NodeType::AskQuestion->value:
                $question = $this->interpolator->interpolate((string) ($config['question'] ?? ''), $resolver);
                $state['transcript'][] = ['from' => 'bot', 'type' => 'text', 'text' => $question, 'at' => now()->toIso8601String()];
                $state['waiting'] = [
                    'kind' => 'question',
                    'node_id' => $node['id'],
                    'save_to' => $config['save_to'] ?? null,
                    'validation' => $config['validation'] ?? 'text',
                    'choices' => $config['choices'] ?? [],
                    'error_message' => $config['error_message'] ?? null,
                ];
                $state['log'][] = $this->logEntry($node['id'], 'Asked question — waiting for reply');

                return [$state, 'wait', 'next'];

            case NodeType::SendButtons->value:
                $body = $this->interpolator->interpolate((string) ($config['body'] ?? ''), $resolver);
                $buttons = array_values(array_map(fn ($b, $i) => [
                    'id' => (string) ($b['id'] ?? 'btn_'.$i),
                    'title' => (string) ($b['title'] ?? 'Option '.($i + 1)),
                ], $config['buttons'] ?? [], array_keys($config['buttons'] ?? [])));

                $state['transcript'][] = [
                    'from' => 'bot', 'type' => 'buttons', 'text' => $body,
                    'buttons' => $buttons, 'at' => now()->toIso8601String(),
                ];
                $state['waiting'] = [
                    'kind' => 'buttons',
                    'node_id' => $node['id'],
                    'buttons' => $buttons,
                    'save_to' => $config['save_to'] ?? null,
                ];
                $state['log'][] = $this->logEntry($node['id'], 'Sent buttons — waiting for choice');

                return [$state, 'wait', 'next'];

            case NodeType::SendList->value:
                $body = $this->interpolator->interpolate((string) ($config['body'] ?? ''), $resolver);
                $sections = $config['sections'] ?? [];
                $rowCount = array_sum(array_map(fn ($s) => count($s['rows'] ?? []), $sections));

                $state['transcript'][] = [
                    'from' => 'bot',
                    'type' => 'list',
                    'text' => $body,
                    'button' => $config['button'] ?? 'Select',
                    'sections' => $sections,
                    'at' => now()->toIso8601String(),
                ];
                $state['log'][] = $this->logEntry($node['id'], "Sent list ({$rowCount} rows)");

                return [$state, 'continue', 'next'];

            case NodeType::Condition->value:
                $result = $this->conditions->evaluate($state, $config);
                $state['log'][] = $this->logEntry($node['id'], 'Condition '.($result ? 'TRUE' : 'FALSE'));

                return [$state, 'continue', $result ? 'true' : 'false'];

            case NodeType::SetCustomField->value:
                $key = $config['field_key'] ?? $config['key'] ?? '';
                $value = $this->interpolator->interpolate((string) ($config['value'] ?? ''), $resolver);
                $state['custom_fields'][$key] = $value;
                $state['log'][] = $this->logEntry($node['id'], "Set custom.{$key} = {$value}");

                return [$state, 'continue', 'next'];

            case NodeType::ClearCustomField->value:
                $key = $config['field_key'] ?? $config['key'] ?? '';
                unset($state['custom_fields'][$key]);
                $state['log'][] = $this->logEntry($node['id'], "Cleared custom.{$key}");

                return [$state, 'continue', 'next'];

            case NodeType::AddTag->value:
                $tag = $config['tag_name'] ?? ('#'.($config['tag_id'] ?? '?'));
                if (! empty($config['tag_id'])) {
                    $tag = Tag::query()->find($config['tag_id'])?->name ?? $tag;
                }
                $state['tags'] = array_values(array_unique([...$state['tags'], $tag]));
                $state['log'][] = $this->logEntry($node['id'], "Added tag {$tag}");

                return [$state, 'continue', 'next'];

            case NodeType::RemoveTag->value:
                $tag = $config['tag_name'] ?? null;
                if (! empty($config['tag_id'])) {
                    $tag = Tag::query()->find($config['tag_id'])?->name ?? $tag;
                }
                $state['tags'] = array_values(array_filter($state['tags'], fn ($t) => $t !== $tag));
                $state['log'][] = $this->logEntry($node['id'], "Removed tag {$tag}");

                return [$state, 'continue', 'next'];

            case NodeType::AssignAgent->value:
                $who = $config['team_id'] ?? null
                    ? ('team #'.$config['team_id'])
                    : ($config['user_id'] ?? null ? 'agent #'.$config['user_id'] : 'auto');
                if (! empty($config['team_id'])) {
                    $who = AgentTeam::query()->find($config['team_id'])?->name ?? $who;
                }
                $state['conversation']['assigned'] = $who;
                $state['log'][] = $this->logEntry($node['id'], "Assigned to {$who} (simulated)");
                if (! empty($config['pause_bot'])) {
                    $state['conversation']['bot'] = 'paused';
                    $state['log'][] = $this->logEntry($node['id'], 'Bot paused');
                }

                return [$state, 'continue', 'next'];

            case NodeType::UnassignAgent->value:
                $state['conversation']['assigned'] = null;
                $state['log'][] = $this->logEntry($node['id'], 'Unassigned');

                return [$state, 'continue', 'next'];

            case NodeType::PauseBot->value:
                $state['conversation']['bot'] = 'paused';
                $state['log'][] = $this->logEntry($node['id'], 'Bot paused');

                return [$state, 'continue', 'next'];

            case NodeType::ResumeBot->value:
                $state['conversation']['bot'] = 'active';
                $state['log'][] = $this->logEntry($node['id'], 'Bot resumed');

                return [$state, 'continue', 'next'];

            case NodeType::CloseConversation->value:
                $state['conversation']['status'] = 'closed';
                $state['log'][] = $this->logEntry($node['id'], 'Conversation closed');

                return [$state, 'stop', 'next'];

            case NodeType::Delay->value:
                $amount = $config['amount'] ?? 1;
                $unit = $config['unit'] ?? 'minutes';
                $state['log'][] = $this->logEntry($node['id'], "Delay {$amount} {$unit} (skipped in simulator)");

                return [$state, 'continue', 'next'];

            case NodeType::WaitUntil->value:
                $state['log'][] = $this->logEntry($node['id'], 'Wait Until (skipped in simulator)');

                return [$state, 'continue', 'next'];

            case NodeType::HttpRequest->value:
            case NodeType::SendWebhook->value:
                $state['log'][] = $this->logEntry($node['id'], 'HTTP request (not executed in simulator)');
                foreach (($config['response_mappings'] ?? []) as $mapping) {
                    if (! empty($mapping['variable'])) {
                        $state['variables'][$mapping['variable']] = '[simulated]';
                    }
                }

                return [$state, 'continue', 'next'];

            case NodeType::AddNote->value:
                $state['log'][] = $this->logEntry($node['id'], 'Internal note added (simulated)');

                return [$state, 'continue', 'next'];

            case NodeType::RandomSplit->value:
                $branches = $config['branches'] ?? [['handle' => 'a', 'weight' => 50], ['handle' => 'b', 'weight' => 50]];
                $handle = $branches[array_rand($branches)]['handle'] ?? 'a';
                $state['log'][] = $this->logEntry($node['id'], "Random split → {$handle}");

                return [$state, 'continue', $handle];

            case NodeType::GoToNode->value:
                return [$state, 'goto', (string) ($config['target_node_id'] ?? '')];

            case NodeType::StartAutomation->value:
                $state['log'][] = $this->logEntry($node['id'], 'Start Automation (not followed in simulator)');

                return [$state, empty($config['stop_parent']) ? 'continue' : 'stop', 'next'];

            case NodeType::Stop->value:
            case NodeType::StopAutomation->value:
                $state['log'][] = $this->logEntry($node['id'], 'Stop');

                return [$state, 'stop', 'next'];

            default:
                $state['log'][] = $this->logEntry($node['id'], 'Unsupported node skipped');

                return [$state, 'continue', 'next'];
        }
    }

    protected function finish(array $state): array
    {
        $state['status'] = 'completed';
        $state['log'][] = $this->logEntry(null, 'Flow completed');

        return $state;
    }

    protected function validateAnswer(string $answer, string $type, array $choices): bool
    {
        if (trim($answer) === '') {
            return false;
        }

        return match ($type) {
            'number' => is_numeric(str_replace([',', ' '], '', $answer)),
            'email' => filter_var($answer, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => preg_match('/^\+?[0-9\s().-]{6,20}$/', $answer) === 1,
            'date' => strtotime($answer) !== false,
            'choice' => $choices === [] || in_array(mb_strtolower($answer), array_map('mb_strtolower', array_map('strval', $choices)), true),
            default => true,
        };
    }

    protected function saveTo(array &$state, string $saveTo, string $value): void
    {
        [$namespace, $key] = array_pad(explode('.', $saveTo, 2), 2, null);

        if (! $key) {
            return;
        }

        if ($namespace === 'custom') {
            $state['custom_fields'][$key] = $value;
        } elseif (in_array($namespace, ['variables', 'variable'], true)) {
            $state['variables'][$key] = $value;
        }
    }

    protected function resolver(array $state): callable
    {
        return function (string $path) use ($state): ?string {
            [$namespace, $key] = array_pad(explode('.', $path, 2), 2, null);

            return match ($namespace) {
                'contact' => match ($key) {
                    'first_name' => $state['contact']['first_name'] ?? null,
                    'last_name' => $state['contact']['last_name'] ?? null,
                    'name', 'full_name' => trim(($state['contact']['first_name'] ?? '').' '.($state['contact']['last_name'] ?? '')),
                    'phone', 'phone_number' => $state['contact']['phone_number'] ?? null,
                    default => null,
                },
                'custom' => $state['custom_fields'][$key] ?? null,
                'variables', 'variable' => $state['variables'][$key] ?? null,
                'workspace' => $key === 'name' ? 'Workspace' : null,
                'message' => $state['last_message'] ?? null,
                default => null,
            };
        };
    }

    protected function findNode(array $definition, string $nodeId): ?array
    {
        foreach ($definition['nodes'] ?? [] as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    protected function nextNode(array $definition, string $nodeId, string $handle): ?string
    {
        $edges = array_values(array_filter(
            $definition['edges'] ?? [],
            fn ($e) => ($e['source'] ?? null) === $nodeId,
        ));

        foreach ($edges as $edge) {
            if (($edge['sourceHandle'] ?? 'next') === $handle) {
                return $edge['target'] ?? null;
            }
        }

        if ($handle === 'next' && count($edges) === 1) {
            return $edges[0]['target'] ?? null;
        }

        return null;
    }

    protected function logEntry(?string $nodeId, string $message): array
    {
        return ['node_id' => $nodeId, 'message' => $message, 'at' => now()->toIso8601String()];
    }

    protected function save(array $state): void
    {
        Cache::put('simulation.'.$state['session_id'], $state, self::TTL);
    }

    protected function load(string $sessionId): ?array
    {
        return Cache::get('simulation.'.$sessionId);
    }

    protected function publicState(array $state): array
    {
        return collect($state)->except(['definition'])->all();
    }
}
