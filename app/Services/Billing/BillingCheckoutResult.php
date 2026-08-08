<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;

final readonly class BillingCheckoutResult
{
    public function __construct(
        public ?Subscription $subscription = null,
        public ?BillingPayment $payment = null,
        public ?string $checkoutUrl = null,
        public bool $requiresPayment = false,
    ) {}

    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription?->loadMissing('plan.features'),
            'payment' => $this->payment,
            'checkout_url' => $this->checkoutUrl,
            'requires_payment' => $this->requiresPayment,
        ];
    }
}
