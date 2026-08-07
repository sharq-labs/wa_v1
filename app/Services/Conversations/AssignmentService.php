<?php

namespace App\Services\Conversations;

use App\Enums\AgentAvailability;
use App\Enums\AgentStatus;
use App\Enums\ConversationStatus;
use App\Events\ConversationAssigned;
use App\Models\AgentProfile;
use App\Models\AgentTeam;
use App\Models\AssignmentState;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_LEAST_ACTIVE = 'least_active';

    /**
     * Assign a conversation to a specific user.
     */
    public function assignToUser(Conversation $conversation, User $user, ?AgentTeam $team = null): Conversation
    {
        $conversation->forceFill([
            'assigned_user_id' => $user->id,
            'assigned_team_id' => $team?->id ?? $conversation->assigned_team_id,
        ])->save();

        broadcast(new ConversationAssigned($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $user->id,
            'assigned_user_name' => $user->name,
            'assigned_team_id' => $conversation->assigned_team_id,
        ]));

        return $conversation;
    }

    /**
     * Assign to a team, optionally picking an agent using a strategy.
     */
    public function assignToTeam(Conversation $conversation, AgentTeam $team, ?string $strategy = null): Conversation
    {
        $strategy = $strategy ?: $team->assignment_strategy ?: self::STRATEGY_ROUND_ROBIN;

        $agent = $this->pickAgent(
            $conversation->workspace,
            $this->teamCandidates($conversation->workspace, $team),
            $strategy,
            'team',
            $team->id,
        );

        if ($agent) {
            $conversation->forceFill([
                'assigned_team_id' => $team->id,
                'assigned_user_id' => $agent->id,
            ])->save();
        } else {
            // No available agent: leave on the team queue, unassigned.
            $conversation->forceFill([
                'assigned_team_id' => $team->id,
                'assigned_user_id' => null,
            ])->save();
        }

        broadcast(new ConversationAssigned($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $agent?->id,
            'assigned_user_name' => $agent?->name,
            'assigned_team_id' => $team->id,
            'assigned_team_name' => $team->name,
        ]));

        return $conversation;
    }

    /**
     * Workspace-wide auto assignment.
     */
    public function autoAssign(Conversation $conversation, string $strategy = self::STRATEGY_ROUND_ROBIN): Conversation
    {
        $agent = $this->pickAgent(
            $conversation->workspace,
            $this->workspaceCandidates($conversation->workspace),
            $strategy,
            'workspace',
            null,
        );

        if ($agent) {
            $this->assignToUser($conversation, $agent);
        }

        return $conversation;
    }

    public function unassign(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'assigned_user_id' => null,
            'assigned_team_id' => null,
        ])->save();

        broadcast(new ConversationAssigned($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
        ]));

        return $conversation;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    protected function pickAgent(Workspace $workspace, Collection $candidates, string $strategy, string $poolType, ?int $poolId): ?User
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        return match ($strategy) {
            self::STRATEGY_LEAST_ACTIVE => $this->pickLeastActive($workspace, $candidates),
            default => $this->pickRoundRobin($workspace, $candidates, $poolType, $poolId),
        };
    }

    /**
     * Candidates must be assignable: available, not over their max
     * concurrent conversations.
     */
    protected function filterAssignable(Workspace $workspace, Collection $users, bool $requireOnline = false): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $profiles = AgentProfile::query()
            ->forWorkspace($workspace)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $activeCounts = Conversation::query()
            ->forWorkspace($workspace)
            ->whereIn('assigned_user_id', $users->pluck('id'))
            ->where('status', '!=', ConversationStatus::Closed)
            ->selectRaw('assigned_user_id, count(*) as total')
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        return $users->filter(function (User $user) use ($profiles, $activeCounts, $requireOnline) {
            $profile = $profiles->get($user->id);

            if (! $profile || $profile->availability !== AgentAvailability::Available) {
                return false;
            }

            if ($requireOnline && $profile->status === AgentStatus::Offline) {
                return false;
            }

            return ($activeCounts[$user->id] ?? 0) < $profile->maximum_conversations;
        })->values();
    }

    protected function teamCandidates(Workspace $workspace, AgentTeam $team): Collection
    {
        return $this->filterAssignable($workspace, $team->members()->get());
    }

    protected function workspaceCandidates(Workspace $workspace): Collection
    {
        return $this->filterAssignable($workspace, $workspace->users()->get());
    }

    /**
     * Round robin with persisted pointer — restarts continue where they left off.
     */
    protected function pickRoundRobin(Workspace $workspace, Collection $candidates, string $poolType, ?int $poolId): ?User
    {
        return DB::transaction(function () use ($workspace, $candidates, $poolType, $poolId) {
            $state = AssignmentState::query()
                ->where('workspace_id', $workspace->id)
                ->where('pool_type', $poolType)
                ->where('pool_id', $poolId)
                ->lockForUpdate()
                ->first();

            if (! $state) {
                $state = AssignmentState::query()->create([
                    'workspace_id' => $workspace->id,
                    'pool_type' => $poolType,
                    'pool_id' => $poolId,
                ]);
            }

            $sorted = $candidates->sortBy('id')->values();
            $lastId = $state->last_assigned_user_id;

            $next = $sorted->first(fn (User $u) => $lastId !== null && $u->id > $lastId) ?? $sorted->first();

            if ($next) {
                $state->forceFill(['last_assigned_user_id' => $next->id])->save();
            }

            return $next;
        });
    }

    /**
     * Least active: fewest open conversations wins; online agents preferred.
     */
    protected function pickLeastActive(Workspace $workspace, Collection $candidates): ?User
    {
        $counts = Conversation::query()
            ->forWorkspace($workspace)
            ->whereIn('assigned_user_id', $candidates->pluck('id'))
            ->where('status', '!=', ConversationStatus::Closed)
            ->selectRaw('assigned_user_id, count(*) as total')
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        $profiles = AgentProfile::query()
            ->forWorkspace($workspace)
            ->whereIn('user_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $candidates
            ->sortBy([
                // online first
                fn (User $a, User $b) => ($profiles[$a->id]?->status === AgentStatus::Online ? 0 : 1)
                    <=> ($profiles[$b->id]?->status === AgentStatus::Online ? 0 : 1),
                // then fewest active conversations
                fn (User $a, User $b) => ($counts[$a->id] ?? 0) <=> ($counts[$b->id] ?? 0),
                // then id for stability
                fn (User $a, User $b) => $a->id <=> $b->id,
            ])
            ->first();
    }
}
