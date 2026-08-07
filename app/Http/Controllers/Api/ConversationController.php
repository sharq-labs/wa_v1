<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationStatus;
use App\Events\AgentTyping;
use App\Http\Resources\ConversationResource;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Conversations\AssignmentService;
use App\Services\Conversations\ConversationService;
use App\Services\Messaging\MessagingEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);

        $query = Conversation::query()
            ->forWorkspace($workspace)
            ->with(['contact.tags', 'assignedUser', 'assignedTeam', 'messages' => fn ($q) => $q->latest()->limit(1)]);

        match ($request->query('scope')) {
            'mine' => $query->where('assigned_user_id', $request->user()->id),
            'unassigned' => $query->whereNull('assigned_user_id'),
            default => null,
        };

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('unread')) {
            $query->where('unread_count', '>', 0);
        }

        if ($userId = $request->query('assigned_user_id')) {
            $query->where('assigned_user_id', $userId);
        }

        if ($teamId = $request->query('assigned_team_id')) {
            $query->where('assigned_team_id', $teamId);
        }

        if ($accountId = $request->query('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }

        if ($tagId = $request->query('tag_id')) {
            $query->whereHas('contact.tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        if ($search = $request->query('search')) {
            $query->whereHas('contact', fn ($q) => $q->search($search));
        }

        $conversations = $query->orderByDesc('last_message_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->success([
            'items' => ConversationResource::collection($conversations->items()),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function show(Request $request, Workspace $workspace, Conversation $conversation, MessagingEligibilityService $eligibility): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $conversation->load(['contact.tags', 'contact.customFieldValues.customField', 'assignedUser', 'assignedTeam']);

        return $this->success([
            'conversation' => new ConversationResource($conversation),
            'eligibility' => $eligibility->state($conversation),
        ]);
    }

    public function assign(Request $request, Workspace $workspace, Conversation $conversation, AssignmentService $assignment, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'strategy' => ['nullable', 'in:round_robin,least_active'],
        ]);

        if (! empty($data['user_id'])) {
            $user = User::query()->findOrFail($data['user_id']);
            abort_unless($user->belongsToWorkspace($workspace), 422, 'User is not a workspace member.');

            $team = ! empty($data['team_id'])
                ? AgentTeam::query()->forWorkspace($workspace)->findOrFail($data['team_id'])
                : null;

            $assignment->assignToUser($conversation, $user, $team);
        } elseif (! empty($data['team_id'])) {
            $team = AgentTeam::query()->forWorkspace($workspace)->findOrFail($data['team_id']);
            $assignment->assignToTeam($conversation, $team, $data['strategy'] ?? null);
        } else {
            return $this->error(__('Provide a user or a team to assign.'));
        }

        $audit->log('conversation.assign', $workspace, $request->user(), $conversation, $data);

        return $this->success(
            new ConversationResource($conversation->fresh(['contact', 'assignedUser', 'assignedTeam'])),
            __('Conversation assigned.'),
        );
    }

    public function unassign(Request $request, Workspace $workspace, Conversation $conversation, AssignmentService $assignment): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $assignment->unassign($conversation);

        return $this->success(new ConversationResource($conversation->fresh(['contact'])), __('Conversation unassigned.'));
    }

    public function setStatus(Request $request, Workspace $workspace, Conversation $conversation, ConversationService $service): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:open,pending,closed'],
        ]);

        $service->setStatus($conversation, ConversationStatus::from($data['status']));

        return $this->success(new ConversationResource($conversation->fresh(['contact'])), __('Conversation updated.'));
    }

    public function pauseBot(Request $request, Workspace $workspace, Conversation $conversation, ConversationService $service): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $service->pauseBot($conversation);

        return $this->success(new ConversationResource($conversation->fresh(['contact'])), __('Bot paused for this conversation.'));
    }

    public function resumeBot(Request $request, Workspace $workspace, Conversation $conversation, ConversationService $service): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $service->resumeBot($conversation);

        return $this->success(new ConversationResource($conversation->fresh(['contact'])), __('Bot resumed for this conversation.'));
    }

    public function markRead(Request $request, Workspace $workspace, Conversation $conversation, ConversationService $service): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $service->markRead($conversation);

        return $this->success(null, __('Marked as read.'));
    }

    public function typing(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        broadcast(new AgentTyping($workspace->id, [
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'typing' => (bool) $request->input('typing', true),
        ]))->toOthers();

        return $this->success(null);
    }
}
