<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Conversations\ConversationService;

class ConversationStatusNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected ConversationService $conversations) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        if (! $context->conversation) {
            return NodeResult::fail('No conversation in context.');
        }

        if ($node['type'] === NodeType::CloseConversation->value) {
            // Closing cancels active runs on the conversation, including this
            // one, so we mark this step and stop afterwards.
            $this->conversations->close($context->conversation);

            return NodeResult::stop(['conversation' => 'closed']);
        }

        $this->conversations->reopen($context->conversation);

        return NodeResult::next('next', ['conversation' => 'reopened']);
    }
}
