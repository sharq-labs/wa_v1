<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaign;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Segment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CampaignService
{
    public function __construct(protected SegmentMatcher $segments) {}

    /**
     * Query of contacts targeted by a campaign's audience config.
     */
    public function audienceQuery(Campaign $campaign): Builder
    {
        return $this->audienceQueryFor(
            $campaign->workspace,
            $campaign->audience_type,
            $campaign->audience_config ?? [],
        );
    }

    /**
     * Build an audience query without persisting a campaign first. This powers
     * the pre-send review screen and keeps preview/send targeting identical.
     */
    public function audienceQueryFor(Workspace $workspace, string $audienceType, array $config = []): Builder
    {
        return match ($audienceType) {
            'tag' => Contact::query()->forWorkspace($workspace)
                ->where('status', 'active')
                ->whereHas('tags', fn ($q) => $q->where('tags.id', $config['tag_id'] ?? 0)),
            'segment' => $this->segmentQueryFor($workspace, $config),
            'contacts' => Contact::query()->forWorkspace($workspace)
                ->where('status', 'active')
                ->whereIn('id', $config['contact_ids'] ?? []),
            default => Contact::query()->forWorkspace($workspace)->where('status', 'active'),
        };
    }

    /**
     * Return WhatsApp-specific eligibility numbers before a broadcast is sent.
     * A contact is eligible when they have explicitly opted in OR they currently
     * have an open customer-service window. Explicit opt-outs always win.
     */
    public function previewAudience(Workspace $workspace, int $accountId, string $audienceType, array $config = []): array
    {
        $base = $this->audienceQueryFor($workspace, $audienceType, $config);
        $cutoff = now()->subHours((int) config('whatsapp.service_window_hours', 24));

        $matching = (clone $base)->count();
        $optedIn = (clone $base)->where('opt_in_status', 'opted_in')->count();
        $blockedOptOut = (clone $base)->where('opt_in_status', 'opted_out')->count();

        $eligibleQuery = clone $base;
        $this->applyEligibility($eligibleQuery, $workspace->id, $accountId, $cutoff);
        $eligible = (clone $eligibleQuery)->count();

        $activeWindowQuery = clone $eligibleQuery;
        $this->applyActiveWindow($activeWindowQuery, $workspace->id, $accountId, $cutoff);
        $activeWindow = $activeWindowQuery->count();

        $blockedNoConsentQuery = clone $base;
        $blockedNoConsentQuery
            ->where('opt_in_status', '!=', 'opted_out')
            ->where('opt_in_status', '!=', 'opted_in');
        $this->applyOutsideWindow($blockedNoConsentQuery, $workspace->id, $accountId, $cutoff);
        $blockedNoConsent = $blockedNoConsentQuery->count();

        $sample = (clone $eligibleQuery)
            ->select(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.display_name', 'contacts.phone_number', 'contacts.opt_in_status'])
            ->limit(5)
            ->get()
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'full_name' => $contact->full_name,
                'phone_number' => $contact->phone_number,
                'opt_in_status' => $contact->opt_in_status,
            ])->values()->all();

        return [
            'matching' => $matching,
            'eligible' => $eligible,
            'active_window' => $activeWindow,
            'outside_window' => max(0, $eligible - $activeWindow),
            'opted_in' => $optedIn,
            'blocked_opt_out' => $blockedOptOut,
            'blocked_no_consent' => $blockedNoConsent,
            'service_window_hours' => (int) config('whatsapp.service_window_hours', 24),
            'sample' => $sample,
        ];
    }

    /**
     * Batch-resolve eligibility for a chunk so campaign materialisation does not
     * perform one conversation lookup per contact.
     */
    public function eligibilityForContacts(Campaign $campaign, Collection $contacts): array
    {
        $contactIds = $contacts->pluck('id')->all();
        if ($contactIds === []) {
            return [];
        }

        $cutoff = now()->subHours((int) config('whatsapp.service_window_hours', 24));
        $windowContactIds = Conversation::query()
            ->where('workspace_id', $campaign->workspace_id)
            ->where('whatsapp_account_id', $campaign->whatsapp_account_id)
            ->whereIn('contact_id', $contactIds)
            ->where('last_inbound_at', '>=', $cutoff)
            ->pluck('contact_id')
            ->flip();

        return $contacts->mapWithKeys(function (Contact $contact) use ($windowContactIds) {
            $activeWindow = $windowContactIds->has($contact->id);
            $eligible = $contact->opt_in_status !== 'opted_out'
                && ($contact->opt_in_status === 'opted_in' || $activeWindow);

            return [$contact->id => [
                'eligible' => $eligible,
                'active_window' => $activeWindow,
                'reason' => $eligible
                    ? null
                    : ($contact->opt_in_status === 'opted_out' ? 'Contact opted out.' : 'No WhatsApp opt-in outside the 24-hour window.'),
            ]];
        })->all();
    }

    public function isContactEligible(Campaign $campaign, Contact $contact): bool
    {
        if ($contact->opt_in_status === 'opted_out') {
            return false;
        }

        if ($contact->opt_in_status === 'opted_in') {
            return true;
        }

        $cutoff = now()->subHours((int) config('whatsapp.service_window_hours', 24));

        return Conversation::query()
            ->where('workspace_id', $campaign->workspace_id)
            ->where('whatsapp_account_id', $campaign->whatsapp_account_id)
            ->where('contact_id', $contact->id)
            ->where('last_inbound_at', '>=', $cutoff)
            ->exists();
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
     * Puts a paused campaign back to work. Existing recipient rows retain their
     * terminal state and only pending recipients are fanned out again.
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

    protected function segmentQueryFor(Workspace $workspace, array $config): Builder
    {
        $segment = Segment::query()
            ->forWorkspace($workspace)
            ->find($config['segment_id'] ?? 0);

        if (! $segment) {
            return Contact::query()->whereRaw('1 = 0');
        }

        return $this->segments->query($workspace, $segment);
    }

    protected function applyEligibility(Builder $query, int $workspaceId, int $accountId, $cutoff): void
    {
        $query->where('opt_in_status', '!=', 'opted_out')
            ->where(function (Builder $eligible) use ($workspaceId, $accountId, $cutoff) {
                $eligible->where('opt_in_status', 'opted_in')
                    ->orWhereExists(function ($window) use ($workspaceId, $accountId, $cutoff) {
                        $window->selectRaw('1')
                            ->from('conversations')
                            ->whereColumn('conversations.contact_id', 'contacts.id')
                            ->where('conversations.workspace_id', $workspaceId)
                            ->where('conversations.whatsapp_account_id', $accountId)
                            ->where('conversations.last_inbound_at', '>=', $cutoff);
                    });
            });
    }

    protected function applyActiveWindow(Builder $query, int $workspaceId, int $accountId, $cutoff): void
    {
        $query->whereExists(function ($window) use ($workspaceId, $accountId, $cutoff) {
            $window->selectRaw('1')
                ->from('conversations')
                ->whereColumn('conversations.contact_id', 'contacts.id')
                ->where('conversations.workspace_id', $workspaceId)
                ->where('conversations.whatsapp_account_id', $accountId)
                ->where('conversations.last_inbound_at', '>=', $cutoff);
        });
    }

    protected function applyOutsideWindow(Builder $query, int $workspaceId, int $accountId, $cutoff): void
    {
        $query->whereNotExists(function ($window) use ($workspaceId, $accountId, $cutoff) {
            $window->selectRaw('1')
                ->from('conversations')
                ->whereColumn('conversations.contact_id', 'contacts.id')
                ->where('conversations.workspace_id', $workspaceId)
                ->where('conversations.whatsapp_account_id', $accountId)
                ->where('conversations.last_inbound_at', '>=', $cutoff);
        });
    }
}
