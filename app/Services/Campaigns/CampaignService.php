<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaign;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Builder;

class CampaignService
{
    public function __construct(protected SegmentMatcher $segments) {}

    /**
     * Query of contacts targeted by a campaign's audience config.
     */
    public function audienceQuery(Campaign $campaign): Builder
    {
        $workspace = $campaign->workspace;
        $config = $campaign->audience_config ?? [];

        return match ($campaign->audience_type) {
            'tag' => Contact::query()->forWorkspace($workspace)
                ->where('status', 'active')
                ->whereHas('tags', fn ($q) => $q->where('tags.id', $config['tag_id'] ?? 0)),
            'segment' => $this->segmentQuery($campaign, $config),
            'contacts' => Contact::query()->forWorkspace($workspace)
                ->where('status', 'active')
                ->whereIn('id', $config['contact_ids'] ?? []),
            default => Contact::query()->forWorkspace($workspace)->where('status', 'active'),
        };
    }

    protected function segmentQuery(Campaign $campaign, array $config): Builder
    {
        $segment = Segment::query()
            ->forWorkspace($campaign->workspace)
            ->find($config['segment_id'] ?? 0);

        if (! $segment) {
            // Empty result set.
            return Contact::query()->whereRaw('1 = 0');
        }

        return $this->segments->query($campaign->workspace, $segment);
    }

    public function schedule(Campaign $campaign, ?\DateTimeInterface $when): Campaign
    {
        $campaign->update([
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => $when ?? now(),
        ]);

        if (! $when || $when <= now()) {
            ProcessCampaign::dispatch($campaign->id);
        } else {
            ProcessCampaign::dispatch($campaign->id)->delay($when);
        }

        return $campaign;
    }

    /**
     * Puts a paused campaign back to work. ProcessCampaign is idempotent — the
     * recipient upsert skips rows that already exist and the fan-out only picks
     * up recipients still pending, so nobody is messaged twice.
     */
    public function resume(Campaign $campaign): Campaign
    {
        $campaign->update(['status' => CampaignStatus::Processing]);

        ProcessCampaign::dispatch($campaign->id);

        return $campaign;
    }

    public function cancel(Campaign $campaign): Campaign
    {
        $campaign->update(['status' => CampaignStatus::Cancelled]);
        $campaign->recipients()->where('status', 'pending')->update(['status' => 'skipped']);

        return $campaign;
    }
}
