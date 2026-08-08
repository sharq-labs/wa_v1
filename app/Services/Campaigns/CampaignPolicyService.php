<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CampaignPolicyService
{
    public function applyFrequencyCap(Builder $contacts, Workspace $workspace, ?string $templateCategory): void
    {
        if (! $this->frequencyCapEnabled($workspace, $templateCategory)) {
            return;
        }

        [$maxMessages, $cutoff] = $this->frequencySettings($workspace);
        $blocked = DB::table('campaign_recipients as recent_recipients')
            ->join('campaigns as recent_campaigns', 'recent_campaigns.id', '=', 'recent_recipients.campaign_id')
            ->join('whatsapp_templates as recent_templates', 'recent_templates.id', '=', 'recent_campaigns.whatsapp_template_id')
            ->where('recent_campaigns.workspace_id', $workspace->id)
            ->whereRaw('UPPER(recent_templates.category) = ?', ['MARKETING'])
            ->whereNotNull('recent_recipients.sent_at')
            ->where('recent_recipients.sent_at', '>=', $cutoff)
            ->groupBy('recent_recipients.contact_id')
            ->havingRaw('COUNT(*) >= ?', [$maxMessages])
            ->select('recent_recipients.contact_id');

        $contacts->whereNotIn('contacts.id', $blocked);
    }

    public function isFrequencyAllowed(Campaign $campaign, Contact $contact): bool
    {
        $campaign->loadMissing(['workspace', 'template']);
        if (! $this->frequencyCapEnabled($campaign->workspace, $campaign->template?->category)) {
            return true;
        }

        [$maxMessages, $cutoff] = $this->frequencySettings($campaign->workspace);

        $count = DB::table('campaign_recipients as recent_recipients')
            ->join('campaigns as recent_campaigns', 'recent_campaigns.id', '=', 'recent_recipients.campaign_id')
            ->join('whatsapp_templates as recent_templates', 'recent_templates.id', '=', 'recent_campaigns.whatsapp_template_id')
            ->where('recent_campaigns.workspace_id', $campaign->workspace_id)
            ->where('recent_recipients.contact_id', $contact->id)
            ->whereRaw('UPPER(recent_templates.category) = ?', ['MARKETING'])
            ->whereNotNull('recent_recipients.sent_at')
            ->where('recent_recipients.sent_at', '>=', $cutoff)
            ->count();

        return $count < $maxMessages;
    }

    /**
     * Seconds until marketing sends are allowed again. Zero means send now.
     * Quiet hours are intentionally workspace-configurable and disabled by
     * default so existing installations keep their current behaviour.
     */
    public function quietHoursDelaySeconds(Workspace $workspace, ?Carbon $now = null): int
    {
        if (! (bool) $workspace->setting('campaign.quiet_hours.enabled', false)) {
            return 0;
        }

        $timezone = (string) $workspace->setting('campaign.quiet_hours.timezone', $workspace->timezone ?: 'UTC');
        $local = ($now ?? now())->copy()->setTimezone($timezone);
        $start = (string) $workspace->setting('campaign.quiet_hours.start', '21:00');
        $end = (string) $workspace->setting('campaign.quiet_hours.end', '08:00');

        [$startHour, $startMinute] = $this->parseTime($start);
        [$endHour, $endMinute] = $this->parseTime($end);
        $startAt = $local->copy()->startOfDay()->setTime($startHour, $startMinute);
        $endAt = $local->copy()->startOfDay()->setTime($endHour, $endMinute);

        if ($startAt->equalTo($endAt)) {
            return 0;
        }

        if ($startAt->lessThan($endAt)) {
            if ($local->betweenIncluded($startAt, $endAt->copy()->subSecond())) {
                return max(1, $local->diffInSeconds($endAt, false));
            }

            return 0;
        }

        // Overnight quiet window, for example 21:00 -> 08:00.
        if ($local->greaterThanOrEqualTo($startAt)) {
            $nextEnd = $endAt->copy()->addDay();

            return max(1, $local->diffInSeconds($nextEnd, false));
        }

        if ($local->lessThan($endAt)) {
            return max(1, $local->diffInSeconds($endAt, false));
        }

        return 0;
    }

    public function frequencyCapEnabled(Workspace $workspace, ?string $templateCategory): bool
    {
        if (strtoupper((string) $templateCategory) !== 'MARKETING') {
            return false;
        }

        return (bool) $workspace->setting('campaign.frequency_cap.enabled', true)
            && (int) $workspace->setting('campaign.frequency_cap.max_messages', 3) > 0;
    }

    public function frequencySummary(Workspace $workspace): array
    {
        return [
            'enabled' => (bool) $workspace->setting('campaign.frequency_cap.enabled', true),
            'max_messages' => max(1, (int) $workspace->setting('campaign.frequency_cap.max_messages', 3)),
            'window_days' => max(1, (int) $workspace->setting('campaign.frequency_cap.window_days', 7)),
        ];
    }

    public function quietHoursSummary(Workspace $workspace): array
    {
        return [
            'enabled' => (bool) $workspace->setting('campaign.quiet_hours.enabled', false),
            'start' => (string) $workspace->setting('campaign.quiet_hours.start', '21:00'),
            'end' => (string) $workspace->setting('campaign.quiet_hours.end', '08:00'),
            'timezone' => (string) $workspace->setting('campaign.quiet_hours.timezone', $workspace->timezone ?: 'UTC'),
        ];
    }

    protected function frequencySettings(Workspace $workspace): array
    {
        $max = max(1, (int) $workspace->setting('campaign.frequency_cap.max_messages', 3));
        $days = max(1, (int) $workspace->setting('campaign.frequency_cap.window_days', 7));

        return [$max, now()->subDays($days)];
    }

    protected function parseTime(string $time): array
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return [min(23, max(0, $hour)), min(59, max(0, $minute))];
    }
}
