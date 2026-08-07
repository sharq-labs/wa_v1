<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
    $workspace = Workspace::query()->find($workspaceId);

    if (! $workspace) {
        return false;
    }

    if (! ($user->is_super_admin || $user->belongsToWorkspace($workspace))) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Presence channel used for agent-collision protection in the inbox.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    if (! $conversation || ! $user->belongsToWorkspace($conversation->workspace_id)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar];
});
