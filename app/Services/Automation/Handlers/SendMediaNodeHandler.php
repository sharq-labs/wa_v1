<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Enums\NodeType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use App\Services\Messaging\MessageService;

class SendMediaNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected VariableInterpolator $interpolator,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $url = (string) ($config['url'] ?? $config['media_url'] ?? '');

        if ($url === '') {
            return NodeResult::fail('Media node has no URL configured.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        $caption = isset($config['caption'])
            ? $this->interpolator->interpolate((string) $config['caption'], $context->resolver())
            : null;

        $type = match ($node['type']) {
            NodeType::SendImage->value => MessageType::Image,
            NodeType::SendVideo->value => MessageType::Video,
            NodeType::SendAudio->value => MessageType::Audio,
            default => MessageType::Document,
        };

        $message = $this->messages->sendMedia($context->conversation, $type, $url, $caption, null, [
            'sender_type' => MessageSenderType::Bot,
            'sync' => true,
        ]);

        return NodeResult::next('next', ['message_id' => $message->id]);
    }
}
