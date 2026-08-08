<?php

namespace App\Services\Notifications;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\OperationalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class WorkspaceNotificationService
{
    public function managers(Workspace $workspace, array $payload): void
    {
        $users = $this->managementUsers($workspace);
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new OperationalNotification($this->payload($workspace, $payload)));
    }

    public function users(Workspace $workspace, iterable $users, array $payload): void
    {
        $collection = collect($users)
            ->filter(fn ($user) => $user instanceof User && $user->belongsToWorkspace($workspace))
            ->unique('id')
            ->values();

        if ($collection->isEmpty()) {
            return;
        }

        Notification::send($collection, new OperationalNotification($this->payload($workspace, $payload)));
    }

    public function user(Workspace $workspace, ?User $user, array $payload): void
    {
        if (! $user || ! $user->belongsToWorkspace($workspace)) {
            return;
        }

        $user->notify(new OperationalNotification($this->payload($workspace, $payload)));
    }

    protected function managementUsers(Workspace $workspace): Collection
    {
        return User::query()
            ->where(function ($query) use ($workspace) {
                $query->whereKey($workspace->owner_id)
                    ->orWhereHas('workspaces', function ($memberships) use ($workspace) {
                        $memberships->where('workspaces.id', $workspace->id)
                            ->whereIn('workspace_users.role', [
                                WorkspaceRole::Owner->value,
                                WorkspaceRole::Admin->value,
                                WorkspaceRole::Manager->value,
                            ]);
                    });
            })
            ->get()
            ->unique('id')
            ->values();
    }

    protected function payload(Workspace $workspace, array $payload): array
    {
        return $payload + [
            'workspace_id' => $workspace->id,
            'email' => (bool) $workspace->setting('notifications.email_enabled', false),
        ];
    }
}
