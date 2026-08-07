<?php

namespace App\Services\Automation;

use App\Enums\NodeType;
use InvalidArgumentException;

/**
 * Maps node types to their dedicated handlers.
 */
class NodeHandlerRegistry
{
    /** @var array<string, class-string<NodeHandlerInterface>> */
    protected array $map = [
        NodeType::SendText->value => Handlers\SendTextNodeHandler::class,
        NodeType::SendImage->value => Handlers\SendMediaNodeHandler::class,
        NodeType::SendVideo->value => Handlers\SendMediaNodeHandler::class,
        NodeType::SendAudio->value => Handlers\SendMediaNodeHandler::class,
        NodeType::SendDocument->value => Handlers\SendMediaNodeHandler::class,
        NodeType::SendTemplate->value => Handlers\SendTemplateNodeHandler::class,
        NodeType::SendButtons->value => Handlers\SendButtonsNodeHandler::class,
        NodeType::SendList->value => Handlers\SendListNodeHandler::class,
        NodeType::AskQuestion->value => Handlers\AskQuestionNodeHandler::class,
        NodeType::SetCustomField->value => Handlers\SetCustomFieldNodeHandler::class,
        NodeType::ClearCustomField->value => Handlers\SetCustomFieldNodeHandler::class,
        NodeType::AddTag->value => Handlers\TagNodeHandler::class,
        NodeType::RemoveTag->value => Handlers\TagNodeHandler::class,
        NodeType::AssignAgent->value => Handlers\AssignAgentNodeHandler::class,
        NodeType::UnassignAgent->value => Handlers\AssignAgentNodeHandler::class,
        NodeType::PauseBot->value => Handlers\BotStatusNodeHandler::class,
        NodeType::ResumeBot->value => Handlers\BotStatusNodeHandler::class,
        NodeType::CloseConversation->value => Handlers\ConversationStatusNodeHandler::class,
        NodeType::ReopenConversation->value => Handlers\ConversationStatusNodeHandler::class,
        NodeType::StartAutomation->value => Handlers\StartAutomationNodeHandler::class,
        NodeType::StopAutomation->value => Handlers\StopNodeHandler::class,
        NodeType::HttpRequest->value => Handlers\HttpRequestNodeHandler::class,
        NodeType::SendWebhook->value => Handlers\HttpRequestNodeHandler::class,
        NodeType::AddNote->value => Handlers\AddNoteNodeHandler::class,
        NodeType::Condition->value => Handlers\ConditionNodeHandler::class,
        NodeType::Delay->value => Handlers\DelayNodeHandler::class,
        NodeType::WaitUntil->value => Handlers\WaitUntilNodeHandler::class,
        NodeType::RandomSplit->value => Handlers\RandomSplitNodeHandler::class,
        NodeType::GoToNode->value => Handlers\GoToNodeHandler::class,
        NodeType::Stop->value => Handlers\StopNodeHandler::class,
    ];

    public function for(string $nodeType): NodeHandlerInterface
    {
        $class = $this->map[$nodeType] ?? null;

        if (! $class) {
            throw new InvalidArgumentException("No handler registered for node type [{$nodeType}].");
        }

        return app($class);
    }

    public function supports(string $nodeType): bool
    {
        return isset($this->map[$nodeType]);
    }
}
