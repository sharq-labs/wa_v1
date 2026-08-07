<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft | published | paused | archived
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->json('draft_definition')->nullable(); // working copy edited by the flow builder
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status', 'priority']);
        });

        Schema::create('automation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition'); // immutable snapshot: nodes + edges + settings
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'version']);
        });

        Schema::create('automation_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_version_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64); // client-side node id from the flow builder
            $table->string('type', 50);
            $table->json('config')->nullable();
            $table->json('position')->nullable();
            $table->timestamps();

            $table->unique(['automation_version_id', 'node_id']);
        });

        Schema::create('automation_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_version_id')->constrained()->cascadeOnDelete();
            $table->string('edge_id', 64);
            $table->string('source_node_id', 64);
            $table->string('source_handle', 64)->nullable(); // e.g. true/false branch, button index
            $table->string('target_node_id', 64);
            $table->timestamps();

            $table->unique(['automation_version_id', 'edge_id']);
            $table->index(['automation_version_id', 'source_node_id']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 20)->default('running'); // running | waiting | paused | completed | failed | cancelled
            $table->string('current_node_id', 64)->nullable();
            $table->unsignedInteger('steps_executed')->default(0);
            $table->unsignedInteger('depth')->default(0); // nested Start Automation depth
            $table->foreignId('parent_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'status']);
            $table->index(['workspace_id', 'status']);
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 50);
            $table->string('status', 20)->default('executed'); // executed | waiting | skipped | failed
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'created_at']);
        });

        // Persisted wait states: Ask Question replies, delays, wait-until.
        Schema::create('automation_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_id', 64);
            $table->string('wait_type', 30); // reply | delay | until
            $table->json('config')->nullable(); // validation, save destination, retry config...
            $table->unsignedInteger('invalid_attempts')->default(0);
            $table->timestamp('resume_at')->nullable(); // for delay / until waits
            $table->string('status', 20)->default('pending'); // pending | resumed | cancelled | expired
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['status', 'resume_at']);
        });

        Schema::create('automation_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'key']);
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('automation_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });
        Schema::dropIfExists('automation_variables');
        Schema::dropIfExists('automation_waits');
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_edges');
        Schema::dropIfExists('automation_nodes');
        Schema::dropIfExists('automation_versions');
        Schema::dropIfExists('automations');
    }
};
