<?php

namespace App\Events;

class AgentStatusChanged extends WorkspaceBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'agent.status';
    }
}
