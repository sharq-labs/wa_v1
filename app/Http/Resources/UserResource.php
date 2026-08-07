<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'avatar' => $this->avatar,
            'is_super_admin' => (bool) $this->is_super_admin,
            'email_verified' => $this->email_verified_at !== null,
            'current_workspace_id' => $this->current_workspace_id,
            'workspaces' => $this->whenLoaded('workspaces', fn () => $this->workspaces->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'logo' => $w->logo,
                'role' => $w->pivot->role,
                'onboarded_at' => $w->onboarded_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
