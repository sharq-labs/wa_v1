<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('meta_template_id')->nullable();
            $table->string('name');
            $table->string('language', 10)->default('en');
            $table->string('category', 30)->default('MARKETING'); // MARKETING | UTILITY | AUTHENTICATION
            $table->string('status', 20)->default('draft'); // draft | pending | approved | rejected | paused | disabled
            $table->string('header_type', 20)->nullable(); // none | text | image | video | document
            $table->text('header_content')->nullable();
            $table->text('body');
            $table->string('footer')->nullable();
            $table->json('buttons')->nullable();
            $table->json('variables')->nullable(); // sample values / example params
            $table->text('rejection_reason')->nullable();
            $table->string('quality_rating', 20)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['whatsapp_account_id', 'name', 'language'], 'templates_unique_name_lang');
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
