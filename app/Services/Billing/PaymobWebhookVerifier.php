<?php

namespace App\Services\Billing;

class PaymobWebhookVerifier
{
    /**
     * Transaction HMAC fields documented by Paymob. The order is significant.
     */
    private const TRANSACTION_FIELDS = [
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

    public function verify(array $object, ?string $providedHmac): bool
    {
        $secret = (string) config('billing.paymob.hmac_secret', '');
        if ($secret === '' || ! is_string($providedHmac) || $providedHmac === '') {
            return false;
        }

        $concatenated = '';
        foreach (self::TRANSACTION_FIELDS as $field) {
            $value = $field === 'order'
                ? (data_get($object, 'order.id') ?? data_get($object, 'order'))
                : data_get($object, $field);

            $concatenated .= $this->stringify($value);
        }

        $calculated = hash_hmac('sha512', $concatenated, $secret);

        return hash_equals(strtolower($calculated), strtolower(trim($providedHmac)));
    }

    private function stringify(mixed $value): string
    {
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
    }
}
