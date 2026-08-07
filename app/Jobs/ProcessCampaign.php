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
        $total = $service->audienceQuery($campaign)->count();

        $service->audienceQuery($campaign)
            ->select(['contacts.id', 'contacts.opt_in_status'])
            ->chunkById($chunkSize, function ($contacts) use ($campaign, $service) {
                $eligibility = $service->eligibilityForContacts($campaign, $contacts);

                $rows = $contacts->map(function ($contact) use ($campaign, $eligibility) {
                    $decision = $eligibility[$contact->id] ?? ['eligible' => false, 'reason' => 'Contact is not eligible.'];

                    return [
                        'campaign_id' => $campaign->id,
                        'contact_id' => $contact->id,
                        'status' => $decision['eligible'] ? 'pending' : 'skipped',
                        'error_message' => $decision['reason'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();

                // Existing rows may already be sent/delivered/read. Never reset
                // them when a scheduler retry materialises the same audience.
                CampaignRecipient::query()->insertOrIgnore($rows);
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

        // Covers an empty audience and an audience where everyone is suppressed.
        if (! $campaign->recipients()->whereIn('status', ['pending', 'processing'])->exists()) {
            $campaign->refresh();
            if ($campaign->status === CampaignStatus::Processing) {
                $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
            }
        }
    }
}
