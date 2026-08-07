<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;

/**
 * Eloquent derives a hasMany foreign key from the parent class name, which turns
 * WhatsAppAccount into `whats_app_account_id` — a column that does not exist.
 * These relations must keep naming `whatsapp_account_id` explicitly; without it
 * every one of them throws "Unknown column", which breaks template syncing.
 */
it('resolves every whatsapp account relation against the real foreign key', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];
    $account = $ctx['account'];

    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $account->id,
    ]);

    $conversation = Conversation::query()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $account->id,
        'contact_id' => $contact->id,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    Message::query()->create([
        'workspace_id' => $workspace->id,
        'conversation_id' => $conversation->id,
        'contact_id' => $contact->id,
        'whatsapp_account_id' => $account->id,
        'direction' => 'inbound',
        'sender_type' => 'contact',
        'message_type' => 'text',
        'content' => 'Hello',
        'status' => 'received',
    ]);

    WhatsAppTemplate::factory()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $account->id,
    ]);

    expect($account->templates()->count())->toBe(1)
        ->and($account->conversations()->count())->toBe(1)
        ->and($account->contacts()->count())->toBe(1)
        ->and($account->messages()->count())->toBe(1);
});

it('syncs templates through the account relation', function () {
    $ctx = createWorkspaceContext();

    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'status' => 'pending',
    ]);

    // Every query inside syncTemplates goes through $account->templates().
    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/whatsapp-accounts/{$ctx['account']->id}/sync-templates")
        ->assertOk();

    expect($template->fresh()->status->value)->toBe('approved')
        ->and($template->fresh()->last_synced_at)->not->toBeNull();
});
