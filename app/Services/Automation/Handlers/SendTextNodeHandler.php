<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use App\Services\Messaging\MessageService;

class SendTextNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected VariableInterpolator $interpolator,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $text = (string) ($config['text'] ?? '');

        if ($text === '') {
            return NodeResult::fail('Send Text node has no text configured.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        try {
            $rendered = $this->interpolator->interpolate(
                $text,
                $context->resolver(),
                $config['missing_behaviour'] ?? VariableInterpolator::MISSING_EMPTY,
                (string) ($config['missing_default'] ?? ''),
            );
        } catch (\RuntimeException $e) {
            return NodeResult::fail($e->getMessage());
        }

        $message = $this->messages->sendText($context->conversation, $rendered, [
            'sender_type' => MessageSenderType::Bot,
            'sync' => true,
        ]);

        return NodeResult::next('next', ['message_id' => $message->id, 'text' => $rendered]);
    }
}
