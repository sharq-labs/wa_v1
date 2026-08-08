<?php

return [
    'provider' => env('BILLING_PROVIDER', 'manual'),

    // Manual billing is intended for development/offline invoicing. Production
    // self-service upgrades must use a payment-backed provider unless explicitly
    // enabled by an operator who handles payment outside the platform.
    'allow_manual_self_service' => (bool) env(
        'BILLING_ALLOW_MANUAL_SELF_SERVICE',
        env('APP_ENV', 'production') !== 'production',
    ),

    'paymob' => [
        // Paymob uses the regional production host for both test/live; the
        // credentials and integration IDs determine the environment.
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'payment_methods' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('PAYMOB_PAYMENT_METHOD_IDS', '')),
        ))),
        'intention_url' => env('PAYMOB_INTENTION_URL', 'https://accept.paymob.com/v1/intention/'),
        'checkout_url' => env('PAYMOB_CHECKOUT_URL', 'https://accept.paymob.com/unifiedcheckout'),
        'webhook_url' => env('PAYMOB_WEBHOOK_URL'),
        'return_url' => env('PAYMOB_RETURN_URL'),
        'timeout' => (int) env('PAYMOB_TIMEOUT', 15),
    ],
];
