<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->belongsToWorkspace($workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Admin);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $workspace->owner_id === $user->id;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Admin);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Admin);
    }

    public function manageWhatsAppAccounts(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Admin);
    }

    public function manageAutomations(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Manager);
    }

    public function manageCampaigns(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Manager);
    }

    public function manageContacts(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Agent);
    }

    public function useInbox(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Agent);
    }

    public function viewOnly(User $user, Workspace $workspace): bool
    {
        return $user->is_super_admin || $user->hasWorkspaceRole($workspace, WorkspaceRole::Viewer);
    }
}
