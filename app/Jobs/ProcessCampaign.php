<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Materialises the audience into campaign_recipients (chunked) and fans out
 * per-recipient send jobs. Never runs inside an HTTP request.
 */
class ProcessCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(CampaignService $service): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if (! $campaign || ! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Processing], true)) {
            return;
        }

        $campaign->update(['status' => CampaignStatus::Processing, 'started_at' => $campaign->started_at ?? now()]);

        $chunkSize = (int) config('whatsapp.campaign_chunk_size', 100);
        $total = 0;

        $service->audienceQuery($campaign)
            ->select('id')
            ->chunkById($chunkSize, function ($contacts) use ($campaign, &$total) {
                $rows = $contacts->map(fn ($c) => [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $c->id,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                // Idempotent: unique(campaign_id, contact_id) — reruns skip existing.
                CampaignRecipient::query()->upsert(
                    $rows,
                    ['campaign_id', 'contact_id'],
                    ['updated_at'],
                );

                $total += count($rows);
            });

        $campaign->update(['total_recipients' => $total]);

        // Fan out sends with pacing to respect provider rate limits.
        $perSecond = max(1, (int) config('whatsapp.campaign_messages_per_second', 10));
        $i = 0;

        $campaign->recipients()
            ->where('status', 'pending')
            ->select('id')
            ->chunkById(500, function ($recipients) use (&$i, $perSecond) {
                foreach ($recipients as $recipient) {
                    SendCampaignMessage::dispatch($recipient->id)
                        ->onQueue('campaigns')
                        ->delay(now()->addSeconds(intdiv($i, $perSecond)));
                    $i++;
                }
            });

        if ($total === 0) {
            $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
        }
    }
}
