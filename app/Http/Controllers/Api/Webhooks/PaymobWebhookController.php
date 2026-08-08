<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BillingPayment;
use App\Services\Billing\PaymobWebhookVerifier;
use App\Services\Billing\SubscriptionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymobWebhookController extends Controller
{
    public function receive(
        Request $request,
        PaymobWebhookVerifier $verifier,
        SubscriptionLifecycleService $lifecycle,
    ): JsonResponse {
        $object = $request->input('obj');
        if (! is_array($object)) {
            $object = $request->all();
            unset($object['hmac']);
        }

        $providedHmac = $request->query('hmac')
            ?? $request->header('X-Paymob-HMAC')
            ?? $request->input('hmac');

        if (! $verifier->verify($object, is_string($providedHmac) ? $providedHmac : null)) {
            Log::warning('Rejected Paymob callback with invalid HMAC.', [
                'transaction_id' => data_get($object, 'id'),
            ]);

            return response()->json(['ok' => false, 'message' => 'Invalid HMAC.'], 401);
        }

        $transactionId = trim((string) data_get($object, 'id', ''));
        if ($transactionId !== '') {
            $existing = BillingPayment::query()
                ->where('provider', 'paymob')
                ->where('provider_transaction_id', $transactionId)
                ->first();

            if ($existing?->isPaid()) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
        }

        $payment = $this->resolvePayment($object);
        if (! $payment) {
            // A valid Paymob callback that cannot be correlated should not be
            // retried forever. Keep it visible in logs for operator review.
            Log::error('Paymob callback could not be correlated to a billing payment.', [
                'transaction_id' => $transactionId ?: null,
                'merchant_reference' => $this->merchantReference($object),
            ]);

            return response()->json(['ok' => true, 'unmatched' => true], 202);
        }

        $amount = (int) (data_get($object, 'amount_cents') ?? data_get($object, 'amount') ?? -1);
        $currency = strtoupper((string) data_get($object, 'currency', ''));

        if ($amount !== (int) $payment->amount_minor || $currency !== strtoupper($payment->currency)) {
            Log::error('Rejected Paymob callback with amount/currency mismatch.', [
                'billing_payment_id' => $payment->id,
                'expected_amount' => $payment->amount_minor,
                'received_amount' => $amount,
                'expected_currency' => $payment->currency,
                'received_currency' => $currency,
            ]);

            return response()->json(['ok' => false, 'message' => 'Payment amount mismatch.'], 422);
        }

        $success = $this->boolValue(data_get($object, 'success')) === true;
        $pending = $this->boolValue(data_get($object, 'pending')) === true;
        $errorOccurred = $this->boolValue(data_get($object, 'error_occured')) === true;

        if (! $success || $pending || $errorOccurred) {
            if (! $payment->isPaid()) {
                $payment->update([
                    'provider_transaction_id' => $transactionId ?: $payment->provider_transaction_id,
                    'status' => 'failed',
                    'failure_reason' => Str::limit((string) (
                        data_get($object, 'data.message')
                        ?? data_get($object, 'txn_response_code')
                        ?? 'Paymob declined the transaction.'
                    ), 1000),
                    'failed_at' => now(),
                ]);
            }

            return response()->json(['ok' => true, 'paid' => false]);
        }

        DB::transaction(function () use ($payment, $transactionId, $lifecycle): void {
            $locked = BillingPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isPaid()) {
                return;
            }

            $locked->update([
                'provider_transaction_id' => $transactionId ?: $locked->provider_transaction_id,
                'status' => 'paid',
                'failure_reason' => null,
                'failed_at' => null,
                'paid_at' => now(),
            ]);

            $lifecycle->activatePaidPayment($locked->fresh());
        });

        return response()->json(['ok' => true, 'paid' => true]);
    }

    protected function resolvePayment(array $object): ?BillingPayment
    {
        $paymentId = data_get($object, 'payment_key_claims.extra.billing_payment_id')
            ?? data_get($object, 'extras.billing_payment_id')
            ?? data_get($object, 'extra.billing_payment_id');

        if (is_numeric($paymentId)) {
            $payment = BillingPayment::query()
                ->where('provider', 'paymob')
                ->find((int) $paymentId);
            if ($payment) {
                return $payment;
            }
        }

        $merchantReference = $this->merchantReference($object);
        if ($merchantReference !== null) {
            $payment = BillingPayment::query()
                ->where('provider', 'paymob')
                ->where('merchant_reference', $merchantReference)
                ->first();
            if ($payment) {
                return $payment;
            }
        }

        $providerReference = data_get($object, 'payment_key_claims.extra.intention_id')
            ?? data_get($object, 'intention_id')
            ?? data_get($object, 'intention.id');

        if (is_scalar($providerReference) && (string) $providerReference !== '') {
            return BillingPayment::query()
                ->where('provider', 'paymob')
                ->where('provider_reference', (string) $providerReference)
                ->first();
        }

        return null;
    }

    protected function merchantReference(array $object): ?string
    {
        $reference = data_get($object, 'payment_key_claims.extra.merchant_reference')
            ?? data_get($object, 'extras.merchant_reference')
            ?? data_get($object, 'special_reference')
            ?? data_get($object, 'order.merchant_order_id');

        return is_scalar($reference) && trim((string) $reference) !== ''
            ? trim((string) $reference)
            : null;
    }

    protected function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes' => true,
                '0', 'false', 'no', '' => false,
                default => null,
            };
        }

        return null;
    }
}
