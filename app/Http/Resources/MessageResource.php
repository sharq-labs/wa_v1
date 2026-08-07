<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'contact_id' => $this->contact_id,
            'direction' => $this->direction?->value,
            'sender_type' => $this->sender_type?->value,
            'sender_user_id' => $this->sender_user_id,
            'sender_user' => $this->whenLoaded('senderUser', fn () => [
                'id' => $this->senderUser->id,
                'name' => $this->senderUser->name,
                'avatar' => $this->senderUser->avatar,
            ]),
            'message_type' => $this->message_type?->value,
            'content' => $this->content,
            'media_url' => $this->media_url,
            'media_mime_type' => $this->media_mime_type,
            'template_name' => $this->template_name,
            'payload' => $this->payload,
            'status' => $this->status?->value,
            'error_message' => $this->error_message,
            'reply_to_message_id' => $this->reply_to_message_id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
