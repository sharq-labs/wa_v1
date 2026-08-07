<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;

/**
 * Payment-provider abstraction for the PLATFORM subscription only.
 *
 * Meta WhatsApp messaging usage is a separate financial concept that belongs
 * to Meta and is never mixed into platform subscription revenue.
 */
interface BillingProviderInterface
{
    public function name(): string;

    /** Start (or switch to) a subscription for the workspace. */
    public function subscribe(Workspace $workspace, Plan $plan, string $billingCycle = 'monthly'): Subscription;

    public function cancel(Subscription $subscription): Subscription;

    /** Provider-hosted checkout/portal URL if the provider has one. */
    public function portalUrl(Workspace $workspace): ?string;
}
