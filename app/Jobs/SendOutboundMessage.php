<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('whatsapp-messages');
    }

    public function handle(MessageService $messages, MessagingManager $manager): void
    {
        $message = Message::query()->find($this->messageId);

        if (! $message || $message->status !== MessageStatus::Queued) {
            return;
        }

        $messages->deliver($message, $manager);
    }
}
