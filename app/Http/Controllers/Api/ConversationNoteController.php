<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

    public function store(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        preg_match_all('/@([\p{L}0-9_.-]+)/u', $data['body'], $matches);

        $note = $conversation->notes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'mentions' => $matches[1] ?? [],
        ]);

        return $this->success($note->load('user:id,name,avatar'), __('Note added.'), 201);
    }
}
