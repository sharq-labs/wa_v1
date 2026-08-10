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

    // A provider send is not safely retryable after the provider may have
    // accepted the request. Claim exactly once and fail closed on crashes.
    public int $tries = 1;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('whatsapp-messages');
    }

    public function handle(MessageService $messages, MessagingManager $manager): void
    {
        $claimed = Message::query()
            ->whereKey($this->messageId)
            ->where('status', MessageStatus::Queued->value)
            ->update(['status' => MessageStatus::Sending->value]);

        if ($claimed !== 1) {
            return;
        }

        $message = Message::query()->find($this->messageId);
        if (! $message) {
            return;
        }

        try {
            $messages->deliver($message, $manager);
        } catch (\Throwable $e) {
            $messages->markFailed(
                $message,
                'delivery_exception',
                mb_substr($e->getMessage(), 0, 2000),
            );
            report($e);
        }
    }
}
