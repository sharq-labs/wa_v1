<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    public function createForUser(User $user, string $name, array $attributes = []): Workspace
    {
        return DB::transaction(function () use ($user, $name, $attributes) {
            $workspace = Workspace::query()->create(array_merge([
                'owner_id' => $user->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'status' => 'active',
            ], $attributes));

            $workspace->users()->attach($user->id, [
                'role' => WorkspaceRole::Owner->value,
                'joined_at' => now(),
            ]);

            $workspace->agentProfiles()->create([
                'user_id' => $user->id,
            ]);

            if (! $user->current_workspace_id) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            // Subscribe to the default plan so entitlements always resolve.
            $defaultPlan = Plan::query()->where('is_default', true)->first()
                ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first();

            if ($defaultPlan) {
                Subscription::query()->create([
                    'workspace_id' => $workspace->id,
                    'plan_id' => $defaultPlan->id,
                    'provider' => 'manual',
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                ]);
            }

            return $workspace;
        });
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 1;

        while (Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
