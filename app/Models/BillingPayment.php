<?php

namespace App\Models;

use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'plan_id',
        'subscription_id',
        'provider',
        'merchant_reference',
        'provider_reference',
        'provider_transaction_id',
        'receipt_number',
        'status',
        'billing_cycle',
        'amount_minor',
        'currency',
        'failure_reason',
        'metadata',
        'paid_at',
        'failed_at',
    ];

    protected static function booted(): void
    {
        static::updated(function (BillingPayment $payment): void {
            if (! $payment->wasChanged('status') || ! in_array($payment->status, ['paid', 'failed'], true)) {
                return;
            }

            $workspace = Workspace::query()->find($payment->workspace_id);
            if (! $workspace) {
                return;
            }

            $paid = $payment->status === 'paid';
            app(WorkspaceNotificationService::class)->managers($workspace, [
                'type' => $paid ? 'billing.payment_paid' : 'billing.payment_failed',
                'title' => $paid ? __('Payment received') : __('Payment failed'),
                'message' => $paid
                    ? __('Paymob confirmed the :cycle plan payment.', ['cycle' => $payment->billing_cycle])
                    : __('Paymob payment failed: :reason', ['reason' => $payment->failure_reason ?: __('No reason provided')]),
                'url' => url('/settings?tab=billing'),
                'severity' => $paid ? 'success' : 'error',
                'meta' => [
                    'payment_id' => $payment->id,
                    'merchant_reference' => $payment->merchant_reference,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                ],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
