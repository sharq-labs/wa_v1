<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('agent_teams')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open | pending | closed
            $table->string('automation_status', 20)->default('active'); // active | paused
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable(); // drives the 24h customer service window
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_agent_reply_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'assigned_user_id']);
            $table->index(['workspace_id', 'assigned_team_id']);
            $table->index(['workspace_id', 'last_message_at']);
            $table->unique(['workspace_id', 'whatsapp_account_id', 'contact_id'], 'conversations_unique_thread');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->string('direction', 10); // inbound | outbound
            $table->string('sender_type', 10); // contact | bot | agent | system
            $table->string('message_type', 20)->default('text');
            $table->text('content')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_mime_type', 100)->nullable();
            $table->string('template_name')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('queued'); // queued | received | sent | delivered | read | failed
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_message_id']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'direction', 'created_at']);
        });

        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
