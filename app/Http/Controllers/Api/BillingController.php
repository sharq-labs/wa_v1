<?php

namespace App\Http\Controllers\Api;

use App\Models\Plan;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\EntitlementsService;
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

    /**
     * Billing overview. Platform subscription and Meta WhatsApp usage are
     * presented as two distinct financial concepts — never merged.
     */
    public function summary(Request $request, Workspace $workspace, EntitlementsService $entitlements): JsonResponse
    {
        Gate::authorize('manageBilling', $workspace);

        return $this->success([
            'platform' => $entitlements->summary($workspace),
            'meta_usage' => [
                'note' => __('WhatsApp conversation charges are billed separately by Meta according to Meta pricing. They are not part of your platform subscription.'),
                'billing_owner' => 'meta',
                // Room for future Meta usage reporting via the Graph API.
                'available' => false,
            ],
        ]);
    }

    public function subscribe(Request $request, Workspace $workspace, BillingProviderInterface $billing, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
        ]);

        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);

        $subscription = $billing->subscribe($workspace, $plan, $data['billing_cycle'] ?? 'monthly');

        $audit->log('subscription.change', $workspace, $request->user(), $subscription, ['plan' => $plan->slug]);

        return $this->success($subscription->load('plan.features'), __('Subscription updated.'));
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
