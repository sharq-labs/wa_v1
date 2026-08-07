<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Conversations\ConversationService;

class BotStatusNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected ConversationService $conversations) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        if (! $context->conversation) {
            return NodeResult::fail('No conversation in context.');
        }

        if ($node['type'] === NodeType::PauseBot->value) {
            $this->conversations->pauseBot($context->conversation);

            return NodeResult::next('next', ['bot' => 'paused']);
        }

        $this->conversations->resumeBot($context->conversation);

        return NodeResult::next('next', ['bot' => 'active']);
    }
}
