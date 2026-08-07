<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'contact_id' => $this->contact_id,
            'contact' => new ContactResource($this->whenLoaded('contact')),
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'avatar' => $this->assignedUser->avatar,
            ] : null),
            'assigned_team_id' => $this->assigned_team_id,
            'assigned_team' => $this->whenLoaded('assignedTeam', fn () => $this->assignedTeam ? [
                'id' => $this->assignedTeam->id,
                'name' => $this->assignedTeam->name,
                'color' => $this->assignedTeam->color,
            ] : null),
            'status' => $this->status?->value,
            'automation_status' => $this->automation_status?->value,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'unread_count' => $this->unread_count,
            'last_message' => $this->whenLoaded('messages', fn () => $this->messages->first()
                ? MessageResource::make($this->messages->first())->resolve()
                : null),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
