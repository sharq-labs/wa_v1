<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class CampaignAttributionService
{
    public function attributeReply(Contact $contact, Message $message): ?CampaignRecipient
    {
        $hours = max(1, (int) $contact->workspace->setting('campaign.attribution.reply_hours', 72));
        $cutoff = $message->created_at->copy()->subHours($hours);

        return DB::transaction(function () use ($contact, $message, $cutoff) {
            $recipient = CampaignRecipient::query()
                ->where('contact_id', $contact->id)
                ->whereNotNull('sent_at')
                ->whereNull('replied_at')
                ->whereBetween('sent_at', [$cutoff, $message->created_at])
                ->whereHas('campaign', function ($query) use ($contact, $message) {
                    $query->where('workspace_id', $contact->workspace_id);
                    if ($message->whatsapp_account_id) {
                        $query->where('whatsapp_account_id', $message->whatsapp_account_id);
                    }
                })
                ->latest('sent_at')
                ->lockForUpdate()
                ->first();

            if (! $recipient) {
                return null;
            }

            $recipient->update(['replied_at' => $message->created_at]);
            Campaign::query()->whereKey($recipient->campaign_id)->increment('replied_count');

            return $recipient->fresh();
        });
    }

    public function recordClick(CampaignRecipient $recipient): CampaignRecipient
    {
        return DB::transaction(function () use ($recipient) {
            $recipient = CampaignRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            $first = $recipient->first_clicked_at === null;

            $recipient->forceFill([
                'click_count' => $recipient->click_count + 1,
                'first_clicked_at' => $recipient->first_clicked_at ?? now(),
                'last_clicked_at' => now(),
            ])->save();

            if ($first) {
                Campaign::query()->whereKey($recipient->campaign_id)->increment('unique_click_count');
            }

            return $recipient->fresh();
        });
    }

    public function recordConversion(
        Campaign $campaign,
        Contact $contact,
        string $name = 'Conversion',
        ?float $value = null,
    ): ?CampaignRecipient {
        return DB::transaction(function () use ($campaign, $contact, $name, $value) {
            $recipient = CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->where('contact_id', $contact->id)
                ->whereNotNull('sent_at')
                ->latest('sent_at')
                ->lockForUpdate()
                ->first();

            if (! $recipient) {
                return null;
            }

            if ($recipient->converted_at !== null) {
                return $recipient;
            }

            $recipient->update([
                'converted_at' => now(),
                'conversion_name' => $name,
                'conversion_value' => $value,
            ]);

            Campaign::query()->whereKey($campaign->id)->increment('conversion_count');
            if ($value !== null) {
                Campaign::query()->whereKey($campaign->id)->increment('conversion_value', $value);
            }

            return $recipient->fresh();
        });
    }
}
