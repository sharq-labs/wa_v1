<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for realtime events broadcast to a workspace channel.
 */
abstract class WorkspaceBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $workspaceId, public readonly array $data) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->workspaceId)];
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }

    public function broadcastQueue(): string
    {
        return 'notifications';
    }
}
