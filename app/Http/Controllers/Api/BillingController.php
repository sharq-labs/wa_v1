<?php

namespace App\Http\Controllers\Api;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\EntitlementsService;
use App\Services\Billing\ManualBillingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingController extends ApiController
{
    public function plans(): JsonResponse
    {
        return $this->success(
            Plan::query()->where('is_active', true)
                ->with('features')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function summary(
        Request $request,
        Workspace $workspace,
        EntitlementsService $entitlements,
        BillingProviderInterface $billing,
    ): JsonResponse {
        Gate::authorize('manageBilling', $workspace);

        return $this->success([
            'platform' => $entitlements->summary($workspace),
            'billing_provider' => [
                'name' => $billing->name(),
                'portal_url' => $billing->portalUrl($workspace),
            ],
            'payments' => BillingPayment::query()
                ->where('workspace_id', $workspace->id)
                ->with('plan:id,name,slug')
                ->latest('id')
                ->limit(10)
                ->get([
                    'id', 'workspace_id', 'plan_id', 'subscription_id', 'provider',
                    'merchant_reference', 'provider_reference', 'provider_transaction_id',
                    'receipt_number', 'status', 'billing_cycle', 'amount_minor',
                    'currency', 'failure_reason', 'paid_at', 'failed_at', 'created_at',
                ]),
            'meta_usage' => [
                'note' => __('WhatsApp conversation charges are billed separately by Meta according to Meta pricing. They are not part of your platform subscription.'),
                'billing_owner' => 'meta',
                'available' => false,
            ],
        ]);
    }

    public function subscribe(Request $request, Workspace $workspace, BillingProviderInterface $billing, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageBilling', $workspace);

        if ($billing instanceof ManualBillingProvider
            && app()->environment('production')
            && ! config('billing.allow_manual_self_service', false)) {
            return $this->error(
                __('Self-service plan changes are disabled until a payment provider is configured.'),
                [],
                503,
            );
        }

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:3'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        $cycle = $data['billing_cycle'] ?? 'monthly';
        $customer = collect($data)->except(['plan_id', 'billing_cycle'])->all();
        $result = $billing->subscribe($workspace, $plan, $cycle, $customer);

        $audit->log(
            $result->requiresPayment ? 'subscription.checkout_started' : 'subscription.change',
            $workspace,
            $request->user(),
            $result->subscription ?? $result->payment,
            [
                'plan' => $plan->slug,
                'billing_cycle' => $cycle,
                'provider' => $billing->name(),
                'payment_id' => $result->payment?->id,
            ],
        );

        return $this->success(
            $result->toArray(),
            $result->requiresPayment
                ? __('Continue to the secure payment page to activate the subscription.')
                : __('Subscription updated.'),
        );
    }

    public function cancel(Request $request, Workspace $workspace, BillingProviderInterface $billing, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $subscription = $workspace->subscription;
        if (! $subscription || ! $subscription->isActive()) {
            return $this->error(__('No active subscription to cancel.'));
        }

        $billing->cancel($subscription);
        $audit->log('subscription.cancel', $workspace, $request->user(), $subscription);

        return $this->success($subscription->fresh(), __('Subscription cancelled.'));
    }
}
