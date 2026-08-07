<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('availability', 20)->default('available');
            $table->string('status', 20)->default('offline');
            $table->unsignedInteger('maximum_conversations')->default(20);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'status', 'availability']);
        });

        Schema::create('agent_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('assignment_strategy', 30)->default('round_robin');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('agent_team_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_team_id', 'user_id']);
        });

        // Persisted round-robin pointers per assignment pool (team or workspace).
        Schema::create('assignment_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('pool_type', 30); // team | workspace
            $table->unsignedBigInteger('pool_id')->nullable();
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'pool_type', 'pool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_states');
        Schema::dropIfExists('agent_team_users');
        Schema::dropIfExists('agent_teams');
        Schema::dropIfExists('agent_profiles');
    }
};
