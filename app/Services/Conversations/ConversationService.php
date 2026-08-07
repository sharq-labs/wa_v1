<?php

namespace App\Services\Conversations;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\ConversationStatus;
use App\Events\AutomationStatusChanged;
use App\Events\ConversationUpdated;
use App\Models\AutomationRun;
use App\Models\Conversation;

class ConversationService
{
    public function pauseBot(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['automation_status' => AutomationStatus::Paused])->save();

        broadcast(new AutomationStatusChanged($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'automation_status' => AutomationStatus::Paused->value,
        ]));

        return $conversation;
    }

    public function resumeBot(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['automation_status' => AutomationStatus::Active])->save();

        broadcast(new AutomationStatusChanged($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'automation_status' => AutomationStatus::Active->value,
        ]));

        return $conversation;
    }

    public function close(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
        ])->save();

        // Cancel active automation runs on this conversation.
        AutomationRun::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', [AutomationRunStatus::Running, AutomationRunStatus::Waiting])
            ->get()
            ->each(function (AutomationRun $run) {
                $run->forceFill(['status' => AutomationRunStatus::Cancelled, 'completed_at' => now()])->save();
                $run->waits()->where('status', 'pending')->update(['status' => 'cancelled']);
            });

        $this->broadcastUpdate($conversation);

        return $conversation;
    }

    public function reopen(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Open,
            'closed_at' => null,
        ])->save();

        $this->broadcastUpdate($conversation);

        return $conversation;
    }

    public function setStatus(Conversation $conversation, ConversationStatus $status): Conversation
    {
        return match ($status) {
            ConversationStatus::Closed => $this->close($conversation),
            ConversationStatus::Open => $this->reopen($conversation),
            ConversationStatus::Pending => tap($conversation, function ($c) {
                $c->forceFill(['status' => ConversationStatus::Pending])->save();
                $this->broadcastUpdate($c);
            }),
        };
    }

    public function markRead(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['unread_count' => 0])->save();
        $this->broadcastUpdate($conversation);

        return $conversation;
    }

    protected function broadcastUpdate(Conversation $conversation): void
    {
        broadcast(new ConversationUpdated($conversation->workspace_id, [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status->value,
            'automation_status' => $conversation->automation_status->value,
            'unread_count' => $conversation->unread_count,
        ]));
    }
}
