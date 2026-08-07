<?php

namespace App\Events;

class AutomationStatusChanged extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'automation.status';
    }
}
