<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Models\AutomationWait;
use App\Models\CustomField;
use App\Models\Message;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use App\Services\Messaging\MessageService;

/**
 * Sends interactive reply buttons and waits for the customer's choice.
 * Each button gets its own outgoing edge (sourceHandle = button id).
 */
class SendButtonsNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected VariableInterpolator $interpolator,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $body = (string) ($config['body'] ?? $config['text'] ?? '');
        $buttons = $config['buttons'] ?? [];

        if ($body === '' || $buttons === []) {
            return NodeResult::fail('Buttons node needs a body and at least one button.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        $rendered = $this->interpolator->interpolate($body, $context->resolver());

        $normalised = array_values(array_map(fn (array $b, int $i) => [
            'id' => (string) ($b['id'] ?? 'btn_'.$i),
            'title' => (string) ($b['title'] ?? $b['label'] ?? 'Option '.($i + 1)),
        ], $buttons, array_keys($buttons)));

        $message = $this->messages->sendButtons($context->conversation, $rendered, $normalised, [
            'sender_type' => MessageSenderType::Bot,
            'sync' => true,
            'header' => $config['header'] ?? null,
            'footer' => $config['footer'] ?? null,
        ]);

        AutomationWait::query()->create([
            'workspace_id' => $context->workspace->id,
            'automation_run_id' => $context->run->id,
            'conversation_id' => $context->conversation->id,
            'node_id' => $node['id'],
            'wait_type' => AutomationWait::TYPE_REPLY,
            'config' => [
                'kind' => 'buttons',
                'buttons' => $normalised,
                'save_to' => $config['save_to'] ?? null, // e.g. custom.service or variables.choice
            ],
            'status' => 'pending',
        ]);

        return NodeResult::wait(['message_id' => $message->id, 'buttons' => $normalised]);
    }

    /**
     * @return array{action: 'continue'|'stay', handle?: string}
     */
    public function handleReply(AutomationContext $context, array $node, AutomationWait $wait, Message $message): array
    {
        $config = $wait->config ?? [];
        $buttons = $config['buttons'] ?? [];

        $replyId = $message->payload['reply_id'] ?? null;
        $text = mb_strtolower(trim((string) $message->content));

        $matched = null;

        foreach ($buttons as $button) {
            if ($replyId !== null && $button['id'] === $replyId) {
                $matched = $button;
                break;
            }

            if ($text !== '' && mb_strtolower($button['title']) === $text) {
                $matched = $button;
                break;
            }
        }

        if (! $matched) {
            // Unrecognised reply: fall through the default edge if present,
            // otherwise stay waiting for a valid button press.
            $default = app(AutomationEngine::class)
                ->resolveNextNode($context->version, $node['id'], 'next');

            return $default ? ['action' => 'continue', 'handle' => 'next'] : ['action' => 'stay'];
        }

        $this->saveChoice($context, $config['save_to'] ?? null, $matched['title']);

        return ['action' => 'continue', 'handle' => $matched['id']];
    }

    protected function saveChoice(AutomationContext $context, ?string $saveTo, string $value): void
    {
        if (! $saveTo) {
            return;
        }

        [$namespace, $key] = array_pad(explode('.', $saveTo, 2), 2, null);

        if (! $key) {
            return;
        }

        if ($namespace === 'custom' && $context->contact) {
            $field = CustomField::query()->forWorkspace($context->workspace)->where('key', $key)->first();
            if ($field) {
                $context->contact->setCustomFieldValue($field, $value);
            }
        } elseif (in_array($namespace, ['variables', 'variable'], true)) {
            $context->setVariable($key, $value);
        }
    }
}
