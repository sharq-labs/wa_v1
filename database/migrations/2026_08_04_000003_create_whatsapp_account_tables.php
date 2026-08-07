<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('meta'); // meta | fake
            $table->string('meta_business_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();
            $table->text('access_token')->nullable(); // encrypted at rest
            $table->timestamp('token_expiration')->nullable();
            $table->string('quality_rating', 20)->nullable();
            $table->string('messaging_limit', 50)->nullable();
            $table->string('status', 20)->default('pending'); // pending | connected | disconnected | error
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index(['phone_number_id']);
            $table->index(['waba_id']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('meta');
            $table->string('event_id')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending | processing | processed | failed | skipped
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
