<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_goal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_id', 100);
            $table->string('goal_name', 120);
            $table->decimal('value', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'node_id']);
            $table->index(['automation_id', 'goal_name', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_goal_events');
    }
};
