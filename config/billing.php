<?php

return [
    // Manual billing is intended for development/offline invoicing. Production
    // self-service upgrades must use a payment-backed provider unless explicitly
    // enabled by an operator who handles payment outside the platform.
    'allow_manual_self_service' => (bool) env(
        'BILLING_ALLOW_MANUAL_SELF_SERVICE',
        env('APP_ENV', 'production') !== 'production',
    ),
];
