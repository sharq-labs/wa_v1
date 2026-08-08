<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PaymobBillingProvider implements BillingProviderInterface
{
    public function __construct(
        protected SubscriptionLifecycleService $lifecycle,
    ) {}

    public function name(): string
    {
        return 'paymob';
    }

    public function subscribe(
        Workspace $workspace,
        Plan $plan,
        string $billingCycle = 'monthly',
        array $customer = [],
    ): BillingCheckoutResult {
        $amount = $billingCycle === 'yearly' ? (int) $plan->price_yearly : (int) $plan->price_monthly;
        $currency = strtoupper($plan->currency ?: $workspace->currency ?: 'EGP');
        $merchantReference = sprintf('wf-%d-%s', $workspace->id, Str::lower((string) Str::uuid()));

        $payment = BillingPayment::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'provider' => $this->name(),
            'merchant_reference' => $merchantReference,
            'status' => $amount === 0 ? 'paid' : 'pending',
            'billing_cycle' => $billingCycle,
            'amount_minor' => $amount,
            'currency' => $currency,
            'paid_at' => $amount === 0 ? now() : null,
            'metadata' => [
                'plan_slug' => $plan->slug,
            ],
        ]);

        if ($amount === 0) {
            $subscription = $this->lifecycle->activatePaidPayment($payment);

            return new BillingCheckoutResult($subscription, $payment->fresh(), null, false);
        }

        $this->ensureConfigured();

        $owner = $workspace->owner;
        $nameParts = preg_split('/\s+/u', trim((string) ($owner?->name ?: $workspace->name)), 2) ?: [];
        $firstName = trim((string) ($customer['first_name'] ?? ($nameParts[0] ?? 'Customer')));
        $lastName = trim((string) ($customer['last_name'] ?? ($nameParts[1] ?? 'Customer')));
        $email = trim((string) ($customer['email'] ?? $owner?->email ?? ''));
        $phone = trim((string) ($customer['phone_number'] ?? $workspace->setting('billing.phone_number', '')));

        if ($phone === '') {
            $payment->update([
                'status' => 'failed',
                'failure_reason' => 'Billing phone number is required before starting Paymob checkout.',
                'failed_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'phone_number' => __('A billing phone number is required for Paymob checkout.'),
            ]);
        }

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'payment_methods' => config('billing.paymob.payment_methods'),
            'items' => [[
                'name' => $plan->name.' - '.ucfirst($billingCycle),
                'amount' => $amount,
                'description' => $plan->description ?: 'WhatsFlow platform subscription',
                'quantity' => 1,
            ]],
            'billing_data' => [
                'apartment' => 'NA',
                'first_name' => $firstName ?: 'Customer',
                'last_name' => $lastName ?: 'Customer',
                'street' => 'NA',
                'building' => 'NA',
                'phone_number' => $phone,
                'city' => (string) ($customer['city'] ?? $workspace->setting('billing.city', 'NA')),
                'country' => strtoupper((string) ($customer['country'] ?? $workspace->country ?? 'EG')),
                'email' => $email,
                'floor' => 'NA',
                'state' => (string) ($customer['state'] ?? 'NA'),
            ],
            'extras' => [
                'billing_payment_id' => $payment->id,
                'merchant_reference' => $merchantReference,
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
            ],
            'special_reference' => $merchantReference,
            'notification_url' => config('billing.paymob.webhook_url') ?: url('/api/webhooks/paymob/transaction'),
            'redirection_url' => config('billing.paymob.return_url') ?: url('/settings/billing'),
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => 'Token '.config('billing.paymob.secret_key'),
                ])
                ->timeout((int) config('billing.paymob.timeout', 15))
                ->post(config('billing.paymob.intention_url'), $payload);

            $response->throw();
            $body = $response->json();

            $providerReference = (string) data_get($body, 'id', '');
            $clientSecret = (string) data_get($body, 'client_secret', '');

            if ($providerReference === '' || $clientSecret === '') {
                throw new RuntimeException('Paymob intention response is missing id or client_secret.');
            }

            $checkoutUrl = rtrim((string) config('billing.paymob.checkout_url'), '/').'/'
                .'?publicKey='.rawurlencode((string) config('billing.paymob.public_key'))
                .'&clientSecret='.rawurlencode($clientSecret);

            $payment->update([
                'provider_reference' => $providerReference,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'intention_id' => $providerReference,
                ]),
            ]);

            return new BillingCheckoutResult(null, $payment->fresh(), $checkoutUrl, true);
        } catch (Throwable $e) {
            $payment->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 1000),
                'failed_at' => now(),
            ]);

            throw $e;
        }
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

    protected function ensureConfigured(): void
    {
        $required = [
            'secret_key' => config('billing.paymob.secret_key'),
            'public_key' => config('billing.paymob.public_key'),
            'hmac_secret' => config('billing.paymob.hmac_secret'),
            'intention_url' => config('billing.paymob.intention_url'),
            'checkout_url' => config('billing.paymob.checkout_url'),
        ];

        foreach ($required as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("Paymob billing is not configured: {$name} is missing.");
            }
        }

        $methods = config('billing.paymob.payment_methods', []);
        if (! is_array($methods) || $methods === []) {
            throw new RuntimeException('Paymob billing is not configured: payment method integration IDs are missing.');
        }
    }
}
