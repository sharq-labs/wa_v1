<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\AuditLogger;
use App\Services\Billing\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $members = $workspace->users()
            ->with(['agentProfiles' => fn ($q) => $q->where('workspace_id', $workspace->id)])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->joined_at,
                'agent_profile' => $user->agentProfiles->first(),
            ]);

        return $this->success($members);
    }

    public function updateRole(Request $request, Workspace $workspace, User $user, AuditLogger $audit, EntitlementsService $entitlements): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $data = $request->validate([
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);

        if ($workspace->owner_id === $user->id) {
            return $this->error(__('The workspace owner role cannot be changed.'));
        }

        if ($data['role'] === WorkspaceRole::Owner->value) {
            return $this->error(__('Ownership must be transferred explicitly; owner cannot be assigned as a normal role.'));
        }

        if (! $user->belongsToWorkspace($workspace)) {
            return $this->error(__('User is not a member of this workspace.'), [], 404);
        }

        $oldRole = $user->roleIn($workspace);
        $newRole = WorkspaceRole::from($data['role']);
        $becomingInboxUser = ($oldRole?->level() ?? 0) < WorkspaceRole::Agent->level()
            && $newRole->atLeast(WorkspaceRole::Agent);

        if ($becomingInboxUser && ! $entitlements->canAddAgent($workspace)) {
            return $this->error(__('Your plan agent limit has been reached. Please upgrade.'), [], 403);
        }

        $workspace->users()->updateExistingPivot($user->id, ['role' => $newRole->value]);

        if ($newRole->atLeast(WorkspaceRole::Agent)) {
            $workspace->agentProfiles()->firstOrCreate(['user_id' => $user->id]);
        } else {
            $workspace->agentProfiles()->where('user_id', $user->id)->delete();
            $workspace->conversations()->where('assigned_user_id', $user->id)->update(['assigned_user_id' => null]);
            foreach ($workspace->teams as $team) {
                $team->members()->detach($user->id);
            }
        }

        $audit->log('member.role_change', $workspace, $request->user(), $user, ['role' => $newRole->value]);

        return $this->success(null, __('Role updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, User $user, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        if ($workspace->owner_id === $user->id) {
            return $this->error(__('The workspace owner cannot be removed.'));
        }

        $workspace->conversations()->where('assigned_user_id', $user->id)->update(['assigned_user_id' => null]);
        foreach ($workspace->teams as $team) {
            $team->members()->detach($user->id);
        }
        $workspace->users()->detach($user->id);
        $workspace->agentProfiles()->where('user_id', $user->id)->delete();

        if ($user->current_workspace_id === $workspace->id) {
            $user->forceFill(['current_workspace_id' => $user->workspaces()->first()?->id])->save();
        }

        $audit->log('member.remove', $workspace, $request->user(), $user);

        return $this->success(null, __('Member removed.'));
    }

    public function invitations(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        return $this->success(
            $workspace->invitations()->where('status', 'pending')->latest()->get(),
        );
    }

    public function invite(Request $request, Workspace $workspace, AuditLogger $audit, EntitlementsService $entitlements): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);

        $role = WorkspaceRole::from($data['role']);
        if ($role === WorkspaceRole::Owner) {
            return $this->error(__('Cannot invite a user as owner.'));
        }

        if ($role->atLeast(WorkspaceRole::Agent) && ! $entitlements->canAddAgent($workspace)) {
            return $this->error(__('Your plan agent limit has been reached. Please upgrade.'), [], 403);
        }

        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing && $existing->belongsToWorkspace($workspace)) {
            return $this->error(__('This user is already a member.'));
        }

        $invitation = $workspace->invitations()->updateOrCreate(
            ['email' => $data['email']],
            [
                'invited_by' => $request->user()->id,
                'role' => $role->value,
                'token' => Str::random(48),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );

        $audit->log('member.invite', $workspace, $request->user(), $invitation, ['email' => $data['email']]);

        return $this->success($invitation, __('Invitation sent.'), 201);
    }

    public function revokeInvitation(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($invitation->workspace_id === $workspace->id, 404);
        $invitation->update(['status' => 'revoked']);

        return $this->success(null, __('Invitation revoked.'));
    }

    public function acceptInvitation(Request $request, AuditLogger $audit, EntitlementsService $entitlements): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $invitation = WorkspaceInvitation::query()
            ->where('token', $data['token'])
            ->where('status', 'pending')
            ->first();

        if (! $invitation || $invitation->isExpired()) {
            return $this->error(__('This invitation is invalid or has expired.'), [], 404);
        }

        $user = $request->user();
        if (strcasecmp($user->email, $invitation->email) !== 0) {
            return $this->error(__('This invitation was sent to a different email address.'), [], 403);
        }

        $workspace = $invitation->workspace;
        $role = WorkspaceRole::from($invitation->role);

        if (! $user->belongsToWorkspace($workspace) && $role->atLeast(WorkspaceRole::Agent) && ! $entitlements->canAddAgent($workspace)) {
            return $this->error(__('This workspace has reached its plan agent limit.'), [], 403);
        }

        if (! $user->belongsToWorkspace($workspace)) {
            $workspace->users()->attach($user->id, [
                'role' => $role->value,
                'joined_at' => now(),
            ]);
            if ($role->atLeast(WorkspaceRole::Agent)) {
                $workspace->agentProfiles()->firstOrCreate(['user_id' => $user->id]);
            }
        }

        $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        $audit->log('member.join', $workspace, $user);

        return $this->success(null, __('Invitation accepted.'));
    }
}
