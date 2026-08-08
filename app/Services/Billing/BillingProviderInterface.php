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

    /**
     * Start a plan purchase/change. Payment-backed providers return a checkout
     * result and MUST NOT grant plan entitlements before confirmed payment.
     */
    public function subscribe(
        Workspace $workspace,
        Plan $plan,
        string $billingCycle = 'monthly',
        array $customer = [],
    ): BillingCheckoutResult;

    public function cancel(Subscription $subscription): Subscription;

    /** Provider-hosted management portal URL if the provider has one. */
    public function portalUrl(Workspace $workspace): ?string;
}
