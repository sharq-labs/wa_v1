<?php

use App\Enums\AutomationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\FakeWhatsAppProvider;

function inboxConversation(array $ctx): Conversation
{
    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'first_name' => 'Ahmed',
    ]);

    return Conversation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'contact_id' => $contact->id,
        'last_inbound_at' => now()->subHour(), // window open
    ]);
}

it('lets an agent reply with free text inside the service window', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/messages/text", [
            'text' => 'Hello from the agent',
        ])
        ->assertCreated();

    $message = Message::query()->where('direction', 'outbound')->first();

    expect($message->content)->toBe('Hello from the agent')
        ->and($message->sender_type->value)->toBe('agent')
        ->and($message->status->value)->toBe('sent')
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
});

it('blocks free text outside the service window and requires a template', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);
    $conversation->forceFill(['last_inbound_at' => now()->subHours(30)])->save();

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/messages/text", [
            'text' => 'Too late for free text',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.window.0', 'closed');

    expect(Message::query()->where('direction', 'outbound')->count())->toBe(0);
});

it('sends an approved template with mapped variables (acceptance §74)', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);

    $serviceField = $ctx['workspace']->customFields()->create(['name' => 'Service', 'key' => 'service', 'type' => 'text']);
    $conversation->contact->setCustomFieldValue($serviceField, 'Website');

    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'name' => 'sales_followup',
        'status' => 'approved',
        'body' => 'Hello {{1}}, we are following up regarding {{2}}.',
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/messages/template", [
            'template_id' => $template->id,
            'variable_mappings' => [
                ['index' => 1, 'source' => 'contact', 'value' => 'first_name'],
                ['index' => 2, 'source' => 'custom', 'value' => 'service'],
            ],
        ])
        ->assertCreated();

    $message = Message::query()->where('message_type', 'template')->first();

    expect($message->content)->toBe('Hello Ahmed, we are following up regarding Website.')
        ->and($message->template_name)->toBe('sales_followup')
        ->and($template->fresh()->usage_count)->toBe(1);

    $sent = FakeWhatsAppProvider::$sent[0];
    expect($sent['type'])->toBe('template')
        ->and($sent['payload']['components'][0]['parameters'][0]['text'])->toBe('Ahmed')
        ->and($sent['payload']['components'][0]['parameters'][1]['text'])->toBe('Website');
});

it('refuses to send unapproved templates', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);

    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'status' => 'pending',
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/messages/template", [
            'template_id' => $template->id,
        ])
        ->assertStatus(422);
});

it('adds internal notes that never reach WhatsApp', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/notes", [
            'body' => 'Customer is VIP, handle with care @Ahmed',
        ])
        ->assertCreated();

    expect($conversation->notes()->count())->toBe(1)
        ->and($conversation->notes()->first()->mentions)->toContain('Ahmed')
        ->and(FakeWhatsAppProvider::$sent)->toBeEmpty()
        ->and(Message::query()->count())->toBe(0);
});

it('pauses and resumes the bot from the inbox', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);

    $base = "/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}";

    $this->actingAs($ctx['user'])->postJson("$base/pause-bot")->assertOk();
    expect($conversation->fresh()->automation_status)->toBe(AutomationStatus::Paused);

    $this->actingAs($ctx['user'])->postJson("$base/resume-bot")->assertOk();
    expect($conversation->fresh()->automation_status)->toBe(AutomationStatus::Active);
});

it('assigns and unassigns conversations manually', function () {
    $ctx = createWorkspaceContext();
    $conversation = inboxConversation($ctx);
    $agent = addAgent($ctx['workspace'], 'Manual Agent');

    $base = "/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}";

    $this->actingAs($ctx['user'])
        ->postJson("$base/assign", ['user_id' => $agent->id])
        ->assertOk();

    expect($conversation->fresh()->assigned_user_id)->toBe($agent->id);

    $this->actingAs($ctx['user'])->postJson("$base/unassign")->assertOk();
    expect($conversation->fresh()->assigned_user_id)->toBeNull();
});

it('filters conversations by scope', function () {
    $ctx = createWorkspaceContext();
    $mine = inboxConversation($ctx);
    $mine->forceFill(['assigned_user_id' => $ctx['user']->id])->save();
    inboxConversation($ctx); // unassigned

    $response = $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/conversations?scope=mine")
        ->assertOk();

    expect($response->json('data.meta.total'))->toBe(1);

    $response = $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/conversations?scope=unassigned")
        ->assertOk();

    expect($response->json('data.meta.total'))->toBe(1);
});
