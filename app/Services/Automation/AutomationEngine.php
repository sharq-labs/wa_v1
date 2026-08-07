<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\NodeType;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\AutomationWait;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Automation\Handlers\AskQuestionNodeHandler;
use App\Services\Automation\Handlers\SendButtonsNodeHandler;
use App\Services\Billing\EntitlementsService;
use Illuminate\Support\Facades\Log;

/**
 * Executes automation runs node by node.
 *
 * Runs are always driven from queue jobs — never synchronously inside a
 * webhook HTTP request. All waiting state is persisted in automation_waits,
 * so server restarts never lose a flow.
 */
class AutomationEngine
{
    public function __construct(
        protected NodeHandlerRegistry $handlers,
        protected EntitlementsService $entitlements,
    ) {}

    /**
     * Start a new run for a published automation version.
     */
    public function start(
        Automation $automation,
        ?Contact $contact,
        ?Conversation $conversation,
        ?Message $triggerMessage = null,
        ?AutomationRun $parentRun = null,
    ): ?AutomationRun {
        $version = $automation->publishedVersion;

        if (! $version) {
            return null;
        }

        $depth = $parentRun ? $parentRun->depth + 1 : 0;

        if ($depth > (int) config('whatsapp.max_automation_depth', 5)) {
            Log::warning('Automation depth limit exceeded', ['automation' => $automation->id]);

            return null;
        }

        if (! $this->entitlements->canRunAutomation($automation->workspace)) {
            Log::info('Automation run blocked by plan limit', ['workspace' => $automation->workspace_id]);

            return null;
        }

        $run = AutomationRun::query()->create([
            'workspace_id' => $automation->workspace_id,
            'automation_id' => $automation->id,
            'automation_version_id' => $version->id,
            'contact_id' => $contact?->id,
            'conversation_id' => $conversation?->id,
            'trigger_message_id' => $triggerMessage?->id,
            'status' => AutomationRunStatus::Running,
            'depth' => $depth,
            'parent_run_id' => $parentRun?->id,
            'started_at' => now(),
        ]);

        $this->entitlements->recordUsage($automation->workspace, 'automation_runs');

        $trigger = $this->findTriggerNode($version);

        if (! $trigger) {
            $this->fail($run, 'No trigger node found in published version.');

            return $run;
        }

        $run->steps()->create([
            'node_id' => $trigger['id'],
            'node_type' => $trigger['type'],
            'status' => 'executed',
            'output' => ['matched' => true],
        ]);

        $next = $this->resolveNextNode($version, $trigger['id'], 'next');

        if (! $next) {
            $this->complete($run);

            return $run;
        }

        $this->executeFrom($run, $next);

        return $run;
    }

    /**
     * Execute nodes starting at $nodeId until the run waits, stops or fails.
     */
    public function executeFrom(AutomationRun $run, string $nodeId): void
    {
        $run->refresh();

        if (! $run->isActive()) {
            return;
        }

        $context = AutomationContext::forRun($run);
        $maxSteps = (int) config('whatsapp.max_automation_steps', 200);
        $current = $nodeId;

        while ($current !== null) {
            if ($run->steps_executed >= $maxSteps) {
                $this->fail($run, "Maximum automation steps ({$maxSteps}) exceeded — possible loop.");

                return;
            }

            $node = $run->version->findNode($current);

            if (! $node) {
                $this->fail($run, "Node [{$current}] not found in version definition.");

                return;
            }

            $run->forceFill([
                'current_node_id' => $current,
                'steps_executed' => $run->steps_executed + 1,
            ])->save();

            $context->currentNode = $node;

            $result = $this->executeNode($context, $node, $run);

            switch ($result->type) {
                case NodeResult::WAIT:
                    $run->forceFill(['status' => AutomationRunStatus::Waiting])->save();

                    return;

                case NodeResult::STOP:
                    $this->complete($run);

                    return;

                case NodeResult::FAIL:
                    $this->fail($run, $result->error ?? 'Node execution failed.');

                    return;

                case NodeResult::GOTO:
                    $current = $result->gotoNodeId;
                    break;

                default:
                    $current = $this->resolveNextNode($run->version, $current, $result->handle);
            }
        }

        $this->complete($run);
    }

    protected function executeNode(AutomationContext $context, array $node, AutomationRun $run): NodeResult
    {
        $step = $run->steps()->create([
            'node_id' => $node['id'],
            'node_type' => $node['type'],
            'status' => 'executed',
            'input' => $node['config'] ?? null,
        ]);

        try {
            $handler = $this->handlers->for($node['type']);
            $result = $handler->handle($context, $node);

            $step->forceFill([
                'status' => $result->type === NodeResult::WAIT ? 'waiting'
                    : ($result->type === NodeResult::FAIL ? 'failed' : 'executed'),
                'output' => $result->output ?: null,
                'error' => $result->error,
            ])->save();

            return $result;
        } catch (\Throwable $e) {
            Log::error('Automation node crashed', [
                'run' => $run->id,
                'node' => $node['id'],
                'error' => $e->getMessage(),
            ]);

            $step->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            return NodeResult::fail($e->getMessage());
        }
    }

    /**
     * Resume a run waiting on a customer reply.
     */
    public function resumeReply(AutomationWait $wait, Message $message): void
    {
        $run = $wait->run;

        if (! $wait->isPending() || ! $run || ! in_array($run->status, [AutomationRunStatus::Waiting, AutomationRunStatus::Running], true)) {
            return;
        }

        $context = AutomationContext::forRun($run);
        $context->latestInboundMessage = $message;

        $node = $run->version->findNode($wait->node_id);

        if (! $node) {
            $wait->update(['status' => 'cancelled']);
            $this->fail($run, 'Waiting node vanished from version.');

            return;
        }

        // Delegate reply handling to the node handler that created the wait.
        $handler = $this->handlers->for($node['type']);

        $resolution = match (true) {
            $handler instanceof AskQuestionNodeHandler => $handler->handleReply($context, $node, $wait, $message),
            $handler instanceof SendButtonsNodeHandler => $handler->handleReply($context, $node, $wait, $message),
            default => ['action' => 'continue', 'handle' => 'next'],
        };

        if (($resolution['action'] ?? 'continue') === 'stay') {
            // Invalid answer: remain waiting.
            return;
        }

        $wait->update(['status' => 'resumed']);
        $run->forceFill(['status' => AutomationRunStatus::Running])->save();

        $run->steps()->create([
            'node_id' => $node['id'],
            'node_type' => $node['type'],
            'status' => 'executed',
            'output' => ['reply' => $message->content, 'resolution' => $resolution],
        ]);

        if (! empty($resolution['goto'])) {
            $this->executeFrom($run, $resolution['goto']);

            return;
        }

        $next = $this->resolveNextNode($run->version, $wait->node_id, $resolution['handle'] ?? 'next');

        if ($next) {
            $this->executeFrom($run, $next);
        } else {
            $this->complete($run);
        }
    }

    /**
     * Resume a run whose delay / wait-until period elapsed.
     */
    public function resumeWait(AutomationWait $wait): void
    {
        $run = $wait->run;

        if (! $wait->isPending() || ! $run || $run->status !== AutomationRunStatus::Waiting) {
            return;
        }

        $wait->update(['status' => 'resumed']);
        $run->forceFill(['status' => AutomationRunStatus::Running])->save();

        $next = $this->resolveNextNode($run->version, $wait->node_id, 'next');

        if ($next) {
            $this->executeFrom($run, $next);
        } else {
            $this->complete($run);
        }
    }

    public function pause(AutomationRun $run): void
    {
        $run->forceFill(['status' => AutomationRunStatus::Paused])->save();
    }

    public function complete(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function fail(AutomationRun $run, string $error): void
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Failed,
            'error' => mb_substr($error, 0, 2000),
            'completed_at' => now(),
        ])->save();
    }

    public function cancel(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        $run->waits()->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    public function findTriggerNode(AutomationVersion $version): ?array
    {
        foreach ($version->definitionNodes() as $node) {
            $type = NodeType::tryFrom($node['type'] ?? '');

            if ($type?->isTrigger()) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Follow the edge whose sourceHandle matches; falls back to the first
     * edge from the node when the requested handle is "next" and only an
     * unlabelled edge exists.
     */
    public function resolveNextNode(AutomationVersion $version, string $nodeId, string $handle = 'next'): ?string
    {
        $edges = array_values(array_filter(
            $version->definitionEdges(),
            fn (array $e) => ($e['source'] ?? null) === $nodeId,
        ));

        foreach ($edges as $edge) {
            $edgeHandle = $edge['sourceHandle'] ?? 'next';

            if ($edgeHandle === $handle || ($edgeHandle === null && $handle === 'next')) {
                return $edge['target'] ?? null;
            }
        }

        // Unlabelled single edge acts as the default "next".
        if ($handle === 'next' && count($edges) === 1) {
            return $edges[0]['target'] ?? null;
        }

        return null;
    }
}
