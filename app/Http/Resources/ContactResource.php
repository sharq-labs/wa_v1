<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wa_id' => $this->wa_id,
            'phone_number' => $this->phone_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'country' => $this->country,
            'language' => $this->language,
            'profile_picture' => $this->profile_picture,
            'status' => $this->status,
            'opt_in_status' => $this->opt_in_status,
            'opt_in_at' => $this->opt_in_at?->toIso8601String(),
            'opt_out_at' => $this->opt_out_at?->toIso8601String(),
            'consent_source' => $this->consent_source,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])),
            'custom_fields' => $this->whenLoaded('customFieldValues', fn () => $this->customFieldValues
                ->filter(fn ($v) => $v->customField)
                ->values()
                ->map(fn ($v) => [
                    'id' => $v->custom_field_id,
                    'key' => $v->customField->key,
                    'name' => $v->customField->name,
                    'type' => $v->customField->type,
                    'value' => $v->value,
                ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
