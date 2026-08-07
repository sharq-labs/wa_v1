<?php

namespace App\Events;

class ConversationUpdated extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }
}
