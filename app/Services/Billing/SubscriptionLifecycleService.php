<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionLifecycleService
{
    public function activatePaidPayment(BillingPayment $payment): Subscription
    {
        return DB::transaction(function () use ($payment) {
            $payment = BillingPayment::query()
                ->with(['workspace', 'plan'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($payment->subscription_id) {
                return Subscription::query()->findOrFail($payment->subscription_id);
            }

            $current = Subscription::query()
                ->where('workspace_id', $payment->workspace_id)
                ->whereIn('status', ['active', 'trialing'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $samePlanRenewal = $current
                && $current->plan_id === $payment->plan_id
                && $current->billing_cycle === $payment->billing_cycle
                && $current->current_period_end?->isFuture();

            $periodStart = $samePlanRenewal ? $current->current_period_end->copy() : now();
            $periodEnd = $payment->billing_cycle === 'yearly'
                ? $periodStart->copy()->addYear()
                : $periodStart->copy()->addMonth();

            if ($current) {
                $current->update([
                    'plan_id' => $payment->plan_id,
                    'provider' => $payment->provider,
                    'provider_subscription_id' => $payment->provider_reference
                        ? 'payment:'.$payment->provider_reference
                        : 'payment:'.$payment->id,
                    'status' => 'active',
                    'billing_cycle' => $payment->billing_cycle,
                    'trial_ends_at' => null,
                    'current_period_start' => $samePlanRenewal
                        ? ($current->current_period_start ?? now())
                        : $periodStart,
                    'current_period_end' => $periodEnd,
                    'cancelled_at' => null,
                ]);

                $subscription = $current->fresh();
            } else {
                $subscription = Subscription::query()->create([
                    'workspace_id' => $payment->workspace_id,
                    'plan_id' => $payment->plan_id,
                    'provider' => $payment->provider,
                    'provider_subscription_id' => $payment->provider_reference
                        ? 'payment:'.$payment->provider_reference
                        : 'payment:'.$payment->id,
                    'status' => 'active',
                    'billing_cycle' => $payment->billing_cycle,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                ]);
            }

            $payment->update([
                'subscription_id' => $subscription->id,
                'receipt_number' => $payment->receipt_number
                    ?: sprintf('WF-%s-%06d', now()->format('Ym'), $payment->id),
            ]);

            return $subscription->fresh();
        });
    }

    public function expirePastDueSubscriptions(): int
    {
        return Subscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->update(['status' => 'expired']);
    }
}
