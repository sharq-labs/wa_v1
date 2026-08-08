<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_schedule_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('contacts_dispatched')->default(0);
            $table->timestamps();

            $table->unique(['automation_id', 'scheduled_for']);
            $table->index(['workspace_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_schedule_ticks');
    }
};
