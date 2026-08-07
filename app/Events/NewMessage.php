<?php

namespace App\Events;

class NewMessage extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'message.new';
    }
}
