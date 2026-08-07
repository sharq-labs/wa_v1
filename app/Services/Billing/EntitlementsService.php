<?php

namespace App\Services\Billing;

use App\Models\SubscriptionUsage;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Central plan-limit / feature gate. Nothing else in the app is allowed to
 * check "if plan == pro" — everything consults this service.
 */
class EntitlementsService
{
    public const UNLIMITED = 'unlimited';

    public function limit(Workspace $workspace, string $key, int $default = 0): ?int
    {
        $plan = $workspace->subscription?->plan;

        if (! $plan) {
            return $default;
        }

        $value = $plan->feature($key);

        if ($value === null) {
            return $default;
        }

        if ($value === self::UNLIMITED) {
            return null; // null = unlimited
        }

        return (int) $value;
    }

    public function hasFeature(Workspace $workspace, string $key): bool
    {
        $value = $workspace->subscription?->plan?->feature($key);

        return $value === 'true' || $value === '1' || $value === self::UNLIMITED
            || (is_numeric($value) && (int) $value > 0);
    }

    protected function withinLimit(Workspace $workspace, string $key, int $currentCount, int $default = 0): bool
    {
        $limit = $this->limit($workspace, $key, $default);

        return $limit === null || $currentCount < $limit;
    }

    public function canAddWhatsAppAccount(Workspace $workspace): bool
    {
        return $this->withinLimit($workspace, 'whatsapp_numbers', $workspace->whatsappAccounts()->count(), 1);
    }

    public function canAddAgent(Workspace $workspace): bool
    {
        return $this->withinLimit($workspace, 'agents', $workspace->users()->count(), 2);
    }

    public function canAddContact(Workspace $workspace): bool
    {
        return $this->withinLimit($workspace, 'contacts', $workspace->contacts()->count(), 2000);
    }

    public function canAddAutomation(Workspace $workspace): bool
    {
        return $this->withinLimit($workspace, 'automations', $workspace->automations()->count(), 10);
    }

    public function canCreateCampaign(Workspace $workspace): bool
    {
        return $this->hasFeature($workspace, 'campaigns');
    }

    public function canUseApi(Workspace $workspace): bool
    {
        return $this->hasFeature($workspace, 'api_access');
    }

    public function canRunAutomation(Workspace $workspace): bool
    {
        $limit = $this->limit($workspace, 'automation_runs_monthly', 0);

        if ($limit === null) {
            return true;
        }

        if ($limit === 0) {
            return true; // unset -> unmetered
        }

        return $this->usage($workspace, 'automation_runs') < $limit;
    }

    public function usage(Workspace $workspace, string $key): int
    {
        return (int) SubscriptionUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('key', $key)
            ->where('period', now()->format('Y-m'))
            ->value('used');
    }

    public function recordUsage(Workspace $workspace, string $key, int $amount = 1): void
    {
        SubscriptionUsage::query()->upsert(
            [[
                'workspace_id' => $workspace->id,
                'key' => $key,
                'period' => now()->format('Y-m'),
                'used' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['workspace_id', 'key', 'period'],
            ['used' => DB::raw('used + '.$amount), 'updated_at' => now()],
        );
    }

    /** @return array<string, mixed> summary for the billing UI */
    public function summary(Workspace $workspace): array
    {
        $subscription = $workspace->subscription?->load('plan.features');
        $plan = $subscription?->plan;

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'currency' => $plan->currency,
                'features' => $plan->features->pluck('value', 'key'),
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            'usage' => [
                'whatsapp_numbers' => [
                    'used' => $workspace->whatsappAccounts()->count(),
                    'limit' => $this->limit($workspace, 'whatsapp_numbers', 1),
                ],
                'agents' => [
                    'used' => $workspace->users()->count(),
                    'limit' => $this->limit($workspace, 'agents', 2),
                ],
                'contacts' => [
                    'used' => $workspace->contacts()->count(),
                    'limit' => $this->limit($workspace, 'contacts', 2000),
                ],
                'automations' => [
                    'used' => $workspace->automations()->count(),
                    'limit' => $this->limit($workspace, 'automations', 10),
                ],
                'automation_runs' => [
                    'used' => $this->usage($workspace, 'automation_runs'),
                    'limit' => $this->limit($workspace, 'automation_runs_monthly', 0),
                ],
            ],
        ];
    }
}
