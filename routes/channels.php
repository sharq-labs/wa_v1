<?php

use App\Enums\WorkspaceRole;
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

    // Workspace events can contain message/contact/automation data. Viewers are
    // deliberately excluded; realtime authorization must be at least as strict
    // as the REST endpoints that expose the same data.
    if (! $user->is_super_admin && ! $user->hasWorkspaceRole($workspace, WorkspaceRole::Agent)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Presence channel used for agent-collision protection in the inbox.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    if (! $conversation) {
        return false;
    }

    $workspace = $conversation->workspace;
    if (! $workspace) {
        return false;
    }

    if (! $user->is_super_admin && ! $user->hasWorkspaceRole($workspace, WorkspaceRole::Agent)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar];
});
