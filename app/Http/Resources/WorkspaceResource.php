<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'status' => $this->status,
            'industry' => $this->industry,
            'website' => $this->website,
            'description' => $this->description,
            'settings' => $this->settings,
            'owner_id' => $this->owner_id,
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
            'role' => $this->when(isset($this->pivot), fn () => $this->pivot->role),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
