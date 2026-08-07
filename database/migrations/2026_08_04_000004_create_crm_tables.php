<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_id')->nullable();
            $table->string('phone_number', 32);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('language', 10)->nullable();
            $table->string('profile_picture')->nullable();
            $table->string('status', 20)->default('active'); // active | archived | blocked
            $table->string('opt_in_status', 20)->default('unknown'); // opted_in | opted_out | unknown
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'phone_number']);
            $table->index(['workspace_id', 'wa_id']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'last_message_at']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('#22c55e');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'tag_id']);
        });

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key');
            $table->string('type', 20)->default('text');
            $table->json('options')->nullable();
            $table->string('default_value')->nullable();
            $table->boolean('required')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('contact_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_custom_field_values');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
    }
};
