<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

class ConversationAssigned extends WorkspaceBroadcastEvent
{
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('workspace.'.$this->workspaceId)];

        if (! empty($this->data['assigned_user_id'])) {
            $channels[] = new PrivateChannel('user.'.$this->data['assigned_user_id']);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.assigned';
    }
}
