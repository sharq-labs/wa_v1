<?php

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;

function configurePaymobForTest(): void
{
    config([
        'billing.provider' => 'paymob',
        'billing.paymob.secret_key' => 'sk_test_123',
        'billing.paymob.public_key' => 'pk_test_123',
        'billing.paymob.hmac_secret' => 'hmac_test_123',
        'billing.paymob.payment_methods' => [123456],
        'billing.paymob.intention_url' => 'https://paymob.test/v1/intention/',
        'billing.paymob.checkout_url' => 'https://paymob.test/unifiedcheckout',
        'billing.paymob.webhook_url' => 'https://app.test/api/webhooks/paymob/transaction',
        'billing.paymob.return_url' => 'https://app.test/settings/billing',
    ]);
}

function paymobTestPlan(array $overrides = []): Plan
{
    return Plan::query()->create(array_merge([
        'name' => 'Growth',
        'slug' => 'growth-'.uniqid(),
        'description' => 'Growth subscription',
        'price_monthly' => 12500,
        'price_yearly' => 125000,
        'currency' => 'EGP',
        'is_active' => true,
    ], $overrides));
}

function paymobTransactionObject(BillingPayment $payment, array $overrides = []): array
{
    return array_replace_recursive([
        'amount_cents' => $payment->amount_minor,
        'created_at' => '2026-08-08T12:00:00.000000Z',
        'currency' => $payment->currency,
        'error_occured' => false,
        'has_parent_transaction' => false,
        'id' => 987654321,
        'integration_id' => 123456,
        'is_3d_secure' => true,
        'is_auth' => false,
        'is_capture' => false,
        'is_refunded' => false,
        'is_standalone_payment' => true,
        'is_voided' => false,
        'order' => [
            'id' => 555777,
            'merchant_order_id' => $payment->merchant_reference,
        ],
        'owner' => 555,
        'pending' => false,
        'source_data' => [
            'pan' => '2346',
            'sub_type' => 'MasterCard',
            'type' => 'card',
        ],
        'success' => true,
        'extras' => [
            'billing_payment_id' => $payment->id,
            'merchant_reference' => $payment->merchant_reference,
        ],
    ], $overrides);
}

function paymobTestHmac(array $object): string
{
    $fields = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    $stringify = static function (mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    };

    $data = '';
    foreach ($fields as $field) {
        $value = $field === 'order'
            ? (data_get($object, 'order.id') ?? data_get($object, 'order'))
            : data_get($object, $field);
        $data .= $stringify($value);
    }

    return hash_hmac('sha512', $data, 'hmac_test_123');
}

it('starts Paymob checkout without granting plan entitlements before payment', function () {
    configurePaymobForTest();
    $ctx = createWorkspaceContext();
    $plan = paymobTestPlan();

    Http::fake([
        'https://paymob.test/v1/intention/' => Http::response([
            'id' => 'intention_abc',
            'client_secret' => 'client_secret_abc',
        ], 201),
    ]);

    $response = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/billing/subscribe", [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ])
        ->assertOk()
        ->assertJsonPath('data.requires_payment', true)
        ->assertJsonPath('data.payment.status', 'pending');

    expect($response->json('data.checkout_url'))
        ->toContain('https://paymob.test/unifiedcheckout')
        ->toContain('publicKey=pk_test_123')
        ->toContain('clientSecret=client_secret_abc');

    $payment = BillingPayment::query()->latest('id')->firstOrFail();
    expect($payment->provider_reference)->toBe('intention_abc')
        ->and($payment->status)->toBe('pending')
        ->and(Subscription::query()->where('workspace_id', $ctx['workspace']->id)->count())->toBe(0);

    Http::assertSent(function ($request) use ($plan, $payment) {
        return $request->url() === 'https://paymob.test/v1/intention/'
            && $request['amount'] === 12500
            && $request['currency'] === 'EGP'
            && data_get($request->data(), 'extras.billing_payment_id') === $payment->id
            && data_get($request->data(), 'extras.plan_id') === $plan->id;
    });
});

it('activates the subscription only after a valid successful Paymob callback', function () {
    configurePaymobForTest();
    $ctx = createWorkspaceContext();
    $plan = paymobTestPlan();

    $payment = BillingPayment::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'provider' => 'paymob',
        'merchant_reference' => 'wf-test-success',
        'provider_reference' => 'intention_success',
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'amount_minor' => $plan->price_monthly,
        'currency' => 'EGP',
    ]);

    $object = paymobTransactionObject($payment);
    $hmac = paymobTestHmac($object);

    $this->postJson('/api/webhooks/paymob/transaction?hmac='.$hmac, [
        'type' => 'TRANSACTION',
        'obj' => $object,
    ])->assertOk()->assertJsonPath('paid', true);

    $payment->refresh();
    $subscription = Subscription::query()
        ->where('workspace_id', $ctx['workspace']->id)
        ->firstOrFail();

    expect($payment->status)->toBe('paid')
        ->and($payment->provider_transaction_id)->toBe('987654321')
        ->and($payment->receipt_number)->not->toBeNull()
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->provider)->toBe('paymob')
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->current_period_end)->not->toBeNull();
});

it('rejects an invalid Paymob HMAC and leaves payment pending', function () {
    configurePaymobForTest();
    $ctx = createWorkspaceContext();
    $plan = paymobTestPlan();

    $payment = BillingPayment::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'provider' => 'paymob',
        'merchant_reference' => 'wf-test-invalid-hmac',
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'amount_minor' => $plan->price_monthly,
        'currency' => 'EGP',
    ]);

    $this->postJson('/api/webhooks/paymob/transaction?hmac=not-valid', [
        'type' => 'TRANSACTION',
        'obj' => paymobTransactionObject($payment),
    ])->assertUnauthorized();

    expect($payment->fresh()->status)->toBe('pending')
        ->and(Subscription::query()->where('workspace_id', $ctx['workspace']->id)->count())->toBe(0);
});

it('is idempotent when Paymob retries a successful transaction callback', function () {
    configurePaymobForTest();
    $ctx = createWorkspaceContext();
    $plan = paymobTestPlan();

    $payment = BillingPayment::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'provider' => 'paymob',
        'merchant_reference' => 'wf-test-duplicate',
        'status' => 'pending',
        'billing_cycle' => 'yearly',
        'amount_minor' => $plan->price_yearly,
        'currency' => 'EGP',
    ]);

    $object = paymobTransactionObject($payment, ['amount_cents' => $plan->price_yearly]);
    $hmac = paymobTestHmac($object);

    $payload = ['type' => 'TRANSACTION', 'obj' => $object];

    $this->postJson('/api/webhooks/paymob/transaction?hmac='.$hmac, $payload)->assertOk();
    $this->postJson('/api/webhooks/paymob/transaction?hmac='.$hmac, $payload)
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect(Subscription::query()->where('workspace_id', $ctx['workspace']->id)->count())->toBe(1)
        ->and(BillingPayment::query()->where('merchant_reference', 'wf-test-duplicate')->count())->toBe(1);
});

it('refuses a valid callback when its correlation identifiers point to different payments', function () {
    configurePaymobForTest();
    $first = createWorkspaceContext();
    $second = createWorkspaceContext();
    $plan = paymobTestPlan();

    $expected = BillingPayment::query()->create([
        'workspace_id' => $first['workspace']->id,
        'plan_id' => $plan->id,
        'provider' => 'paymob',
        'merchant_reference' => 'wf-test-correlation-original',
        'provider_reference' => 'intention_original',
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'amount_minor' => $plan->price_monthly,
        'currency' => 'EGP',
    ]);

    $other = BillingPayment::query()->create([
        'workspace_id' => $second['workspace']->id,
        'plan_id' => $plan->id,
        'provider' => 'paymob',
        'merchant_reference' => 'wf-test-correlation-other',
        'provider_reference' => 'intention_other',
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'amount_minor' => $plan->price_monthly,
        'currency' => 'EGP',
    ]);

    $object = paymobTransactionObject($expected, [
        'extras' => [
            'billing_payment_id' => $other->id,
            'merchant_reference' => $expected->merchant_reference,
        ],
    ]);
    $hmac = paymobTestHmac($object);

    $this->postJson('/api/webhooks/paymob/transaction?hmac='.$hmac, [
        'type' => 'TRANSACTION',
        'obj' => $object,
    ])->assertStatus(202)->assertJsonPath('unmatched', true);

    expect($expected->fresh()->status)->toBe('pending')
        ->and($expected->fresh()->subscription_id)->toBeNull()
        ->and($other->fresh()->status)->toBe('pending')
        ->and($other->fresh()->subscription_id)->toBeNull();
});
