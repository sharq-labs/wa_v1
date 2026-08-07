<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\Messaging\WhatsAppEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('whatsapp-webhooks');
    }

    public function handle(WhatsAppEventProcessor $processor): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if (! $event || $event->status === WebhookEventStatus::Processed) {
            return;
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Processing,
            'attempts' => $event->attempts + 1,
        ])->save();

        try {
            $processor->process($event);

            $event->forceFill([
                'status' => WebhookEventStatus::Processed,
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (\Throwable $e) {
            $event->forceFill([
                'status' => WebhookEventStatus::Failed,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw $e;
        }
    }
}
