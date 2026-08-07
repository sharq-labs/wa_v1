<?php

namespace App\Events;

class ContactUpdated extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'contact.updated';
    }
}
