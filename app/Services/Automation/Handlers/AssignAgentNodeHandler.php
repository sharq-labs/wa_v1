<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Models\AgentTeam;
use App\Models\User;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Conversations\AssignmentService;
use App\Services\Conversations\ConversationService;

/**
 * Human handoff node. Assigns an agent or team (with strategy) and can
 * optionally pause the bot afterwards.
 */
class AssignAgentNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected AssignmentService $assignment,
        protected ConversationService $conversations,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $conversation = $context->conversation;

        if (! $conversation) {
            return NodeResult::fail('No conversation in context.');
        }

        if ($node['type'] === NodeType::UnassignAgent->value) {
            $this->assignment->unassign($conversation);

            return NodeResult::next('next', ['unassigned' => true]);
        }

        $config = $node['config'] ?? [];
        $output = [];

        if (! empty($config['user_id'])) {
            $user = User::query()->find($config['user_id']);

            if (! $user || ! $user->belongsToWorkspace($context->workspace)) {
                return NodeResult::fail('Configured agent is not a workspace member.');
            }

            $this->assignment->assignToUser($conversation, $user);
            $output['assigned_user'] = $user->name;
        } elseif (! empty($config['team_id'])) {
            $team = AgentTeam::query()->forWorkspace($context->workspace)->find($config['team_id']);

            if (! $team) {
                return NodeResult::fail('Configured team no longer exists.');
            }

            $this->assignment->assignToTeam($conversation, $team, $config['strategy'] ?? null);
            $conversation->refresh();
            $output['assigned_team'] = $team->name;
            $output['assigned_user'] = $conversation->assignedUser?->name;
        } else {
            $this->assignment->autoAssign($conversation, $config['strategy'] ?? AssignmentService::STRATEGY_ROUND_ROBIN);
            $conversation->refresh();
            $output['assigned_user'] = $conversation->assignedUser?->name;
        }

        if (! empty($config['pause_bot'])) {
            $this->conversations->pauseBot($conversation);
            $output['bot_paused'] = true;
        }

        return NodeResult::next('next', $output);
    }
}
