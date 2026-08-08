<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;

/**
 * Manual billing for local development and offline invoicing. Subscriptions
 * activate immediately; payment collection happens outside the system.
 */
class ManualBillingProvider implements BillingProviderInterface
{
    public function name(): string
    {
        return 'manual';
    }

    public function subscribe(
        Workspace $workspace,
        Plan $plan,
        string $billingCycle = 'monthly',
        array $customer = [],
    ): BillingCheckoutResult {
        $current = $workspace->subscription;

        if ($current && $current->isActive()) {
            $current->update([
                'plan_id' => $plan->id,
                'provider' => $this->name(),
                'billing_cycle' => $billingCycle,
                'current_period_start' => now(),
                'current_period_end' => $billingCycle === 'yearly' ? now()->addYear() : now()->addMonth(),
                'cancelled_at' => null,
            ]);

            return new BillingCheckoutResult($current->fresh(), null, null, false);
        }

        $subscription = Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'provider' => $this->name(),
            'status' => 'active',
            'billing_cycle' => $billingCycle,
            'current_period_start' => now(),
            'current_period_end' => $billingCycle === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        return new BillingCheckoutResult($subscription, null, null, false);
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $subscription->fresh();
    }

    public function portalUrl(Workspace $workspace): ?string
    {
        return null;
    }
}
