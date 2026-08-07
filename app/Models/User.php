<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'avatar',
        'current_workspace_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function agentProfiles(): HasMany
    {
        return $this->hasMany(AgentProfile::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(AgentTeam::class, 'agent_team_users')->withTimestamps();
    }

    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $membership = $this->workspaces()->where('workspaces.id', $id)->first();

        return $membership ? WorkspaceRole::from($membership->pivot->role) : null;
    }

    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        return $this->roleIn($workspace) !== null;
    }

    public function hasWorkspaceRole(Workspace|int $workspace, WorkspaceRole $minimum): bool
    {
        $role = $this->roleIn($workspace);

        return $role !== null && $role->atLeast($minimum);
    }
}
