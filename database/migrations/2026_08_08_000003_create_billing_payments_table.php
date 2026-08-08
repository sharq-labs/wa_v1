<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->default('paymob');
            $table->string('merchant_reference', 96)->unique();
            $table->string('provider_reference')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->string('status', 20)->default('pending'); // pending | paid | failed | cancelled | refunded
            $table->string('billing_cycle', 10)->default('monthly');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['workspace_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
