<?php

use App\Enums\CampaignStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\WhatsAppTemplate;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\FakeWhatsAppProvider;
use App\Services\Messaging\WhatsAppEventProcessor;

function enhancedCampaignContext(): array
{
    $ctx = createWorkspaceContext();

    $plan = Plan::query()->create([
        'name' => 'Campaign Pro',
        'slug' => 'campaign-pro-'.uniqid(),
        'is_active' => true,
    ]);
    $plan->features()->createMany([
        ['key' => 'campaigns', 'value' => 'true'],
        ['key' => 'contacts', 'value' => 'unlimited'],
    ]);
    Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $ctx['template'] = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'status' => 'approved',
        'body' => 'Hello {{1}}',
    ]);

    return $ctx;
}

it('previews WhatsApp campaign eligibility and suppresses contacts without permission', function () {
    $ctx = enhancedCampaignContext();
    $workspace = $ctx['workspace'];

    $optedIn = Contact::factory()->create(['workspace_id' => $workspace->id, 'opt_in_status' => 'opted_in']);
    $optedOut = Contact::factory()->create(['workspace_id' => $workspace->id, 'opt_in_status' => 'opted_out']);
    $unknownOutside = Contact::factory()->create(['workspace_id' => $workspace->id, 'opt_in_status' => 'unknown']);
    $unknownInside = Contact::factory()->create(['workspace_id' => $workspace->id, 'opt_in_status' => 'unknown']);

    Conversation::query()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'contact_id' => $unknownInside->id,
        'status' => 'open',
        'automation_status' => 'active',
        'last_inbound_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($ctx['user'])->postJson("/api/workspaces/{$workspace->id}/campaigns/preview", [
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'audience_type' => 'all',
    ])->assertOk();

    expect($response->json('data.matching'))->toBe(4)
        ->and($response->json('data.eligible'))->toBe(2)
        ->and($response->json('data.active_window'))->toBe(1)
        ->and($response->json('data.outside_window'))->toBe(1)
        ->and($response->json('data.blocked_opt_out'))->toBe(1)
        ->and($response->json('data.blocked_no_consent'))->toBe(1);

    $campaign = Campaign::query()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Consent aware',
        'status' => CampaignStatus::Draft,
        'audience_type' => 'all',
        'variable_mappings' => [['index' => 1, 'source' => 'contact', 'value' => 'first_name']],
    ]);

    app(CampaignService::class)->schedule($campaign, null);

    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->total_recipients)->toBe(4)
        ->and($campaign->sent_count)->toBe(2)
        ->and($campaign->recipients()->where('status', 'skipped')->count())->toBe(2)
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(2);

    expect($campaign->recipients()->where('contact_id', $optedIn->id)->value('status'))->toBe('sent')
        ->and($campaign->recipients()->where('contact_id', $unknownInside->id)->value('status'))->toBe('sent')
        ->and($campaign->recipients()->where('contact_id', $optedOut->id)->value('status'))->toBe('skipped')
        ->and($campaign->recipients()->where('contact_id', $unknownOutside->id)->value('status'))->toBe('skipped');
});

it('duplicates a campaign as a clean draft', function () {
    $ctx = enhancedCampaignContext();
    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Winning campaign',
        'status' => CampaignStatus::Completed,
        'audience_type' => 'contacts',
        'audience_config' => ['contact_ids' => [10, 11]],
        'variable_mappings' => [['index' => 1, 'source' => 'static', 'value' => 'Friend']],
        'total_recipients' => 50,
        'sent_count' => 45,
        'delivered_count' => 42,
        'read_count' => 30,
        'failed_count' => 3,
    ]);

    $response = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns/{$campaign->id}/duplicate")
        ->assertCreated();

    $copy = Campaign::query()->findOrFail($response->json('data.id'));
    expect($copy->status)->toBe(CampaignStatus::Draft)
        ->and($copy->audience_config)->toBe($campaign->audience_config)
        ->and($copy->variable_mappings)->toBe($campaign->variable_mappings)
        ->and($copy->total_recipients)->toBe(0)
        ->and($copy->sent_count)->toBe(0)
        ->and($copy->delivered_count)->toBe(0)
        ->and($copy->read_count)->toBe(0);
});

it('updates campaign delivery and read counters from Meta status webhooks', function () {
    $ctx = enhancedCampaignContext();
    $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id, 'opt_in_status' => 'opted_in']);
    $conversation = Conversation::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'contact_id' => $contact->id,
        'status' => 'open',
        'automation_status' => 'active',
    ]);
    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Analytics',
        'status' => CampaignStatus::Completed,
        'audience_type' => 'all',
        'total_recipients' => 1,
        'sent_count' => 1,
    ]);
    $message = Message::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'conversation_id' => $conversation->id,
        'contact_id' => $contact->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'provider_message_id' => 'wamid.campaign.analytics',
        'direction' => MessageDirection::Outbound,
        'sender_type' => MessageSenderType::System,
        'message_type' => MessageType::Template,
        'status' => MessageStatus::Sent,
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'message_id' => $message->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $processor = app(WhatsAppEventProcessor::class);
    foreach (['delivered', 'read'] as $index => $status) {
        $event = WebhookEvent::query()->create([
            'provider' => 'meta',
            'event_id' => 'campaign-status-'.$index,
            'event_type' => 'messages',
            'workspace_id' => $ctx['workspace']->id,
            'status' => 'received',
            'payload' => [
                'entry' => [[
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => $ctx['account']->phone_number_id],
                            'statuses' => [[
                                'id' => 'wamid.campaign.analytics',
                                'status' => $status,
                            ]],
                        ],
                    ]],
                ]],
            ],
        ]);
        $processor->process($event);
    }

    $campaign->refresh();
    expect($campaign->delivered_count)->toBe(1)
        ->and($campaign->read_count)->toBe(1)
        ->and($recipient->fresh()->status)->toBe('read')
        ->and($message->fresh()->status)->toBe(MessageStatus::Read);
});
