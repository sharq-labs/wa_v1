<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('status', 20)->default('uploaded'); // uploaded|queued|processing|completed|failed
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
