<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConversationNoteController extends ApiController
{
    public function index(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        return $this->success(
            $conversation->notes()->with('user:id,name,avatar')->orderBy('created_at')->get(),
        );
    }

    public function store(
        Request $request,
        Workspace $workspace,
        Conversation $conversation,
        WorkspaceNotificationService $notifications,
    ): JsonResponse {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'mention_user_ids' => ['sometimes', 'array', 'max:20'],
            'mention_user_ids.*' => [
                'integer',
                Rule::exists('workspace_users', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        preg_match_all('/@([\p{L}0-9_.-]+)/u', $data['body'], $matches);
        $mentionIds = collect($data['mention_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id !== $request->user()->id)
            ->unique()
            ->values();

        $note = $conversation->notes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'mentions' => [
                'tokens' => $matches[1] ?? [],
                'user_ids' => $mentionIds->all(),
            ],
        ]);

        if ($mentionIds->isNotEmpty()) {
            $mentionedUsers = User::query()->whereIn('id', $mentionIds)->get();
            $notifications->users($workspace, $mentionedUsers, [
                'type' => 'conversation.mention',
                'title' => __('You were mentioned in an internal note'),
                'message' => mb_strimwidth($request->user()->name.': '.$data['body'], 0, 220, '…'),
                'url' => url('/inbox?conversation='.$conversation->id),
                'severity' => 'info',
                'meta' => [
                    'conversation_id' => $conversation->id,
                    'note_id' => $note->id,
                    'mentioned_by' => $request->user()->id,
                ],
            ]);
        }

        return $this->success($note->load('user:id,name,avatar'), __('Note added.'), 201);
    }
}
