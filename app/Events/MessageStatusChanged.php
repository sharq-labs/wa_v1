<?php

namespace App\Events;

class MessageStatusChanged extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'message.status';
    }
}
