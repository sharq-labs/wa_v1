<?php

use App\Enums\CampaignStatus;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WhatsAppTemplate;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\SegmentMatcher;
use App\Services\Messaging\FakeWhatsAppProvider;

function campaignContext(): array
{
    $ctx = createWorkspaceContext();

    // Pro-style subscription that includes campaigns.
    $plan = Plan::query()->create([
        'name' => 'Pro', 'slug' => 'pro-test', 'is_active' => true,
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
        'body' => 'Hello {{1}}!',
    ]);

    return $ctx;
}

it('creates, schedules and sends a campaign through queues', function () {
    $ctx = campaignContext();

    Contact::factory()->count(3)->create([
        'workspace_id' => $ctx['workspace']->id,
        'opt_in_status' => 'opted_in',
    ]);

    $response = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns", [
            'name' => 'Spring Promo',
            'whatsapp_account_id' => $ctx['account']->id,
            'whatsapp_template_id' => $ctx['template']->id,
            'audience_type' => 'all',
            'variable_mappings' => [['index' => 1, 'source' => 'contact', 'value' => 'first_name']],
        ])
        ->assertCreated();

    expect($response->json('data.audience_count'))->toBe(3);

    $campaign = Campaign::query()->first();

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns/{$campaign->id}/schedule")
        ->assertOk();

    $campaign->refresh();

    expect($campaign->status)->toBe(CampaignStatus::Completed) // sync queue finishes immediately
        ->and($campaign->total_recipients)->toBe(3)
        ->and($campaign->sent_count)->toBe(3)
        ->and($campaign->recipients()->where('status', 'sent')->count())->toBe(3)
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(3);
});

it('skips opted-out contacts', function () {
    $ctx = campaignContext();

    Contact::factory()->create(['workspace_id' => $ctx['workspace']->id, 'opt_in_status' => 'opted_in']);
    Contact::factory()->create(['workspace_id' => $ctx['workspace']->id, 'opt_in_status' => 'opted_out']);

    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'name' => 'Optout Test',
        'status' => 'draft',
        'audience_type' => 'all',
    ]);

    app(CampaignService::class)->schedule($campaign, null);

    expect($campaign->fresh()->sent_count)->toBe(1)
        ->and($campaign->recipients()->where('status', 'skipped')->count())->toBe(1);
});

it('keeps paused recipients pending and re-sends them on resume', function () {
    $ctx = campaignContext();

    Contact::factory()->count(2)->create([
        'workspace_id' => $ctx['workspace']->id,
        'opt_in_status' => 'opted_in',
    ]);

    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'name' => 'Paused Promo',
        'status' => CampaignStatus::Paused,
        'audience_type' => 'all',
    ]);

    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => Contact::query()->first()->id,
        'status' => 'pending',
    ]);

    // Sends already queued when the pause landed must not burn the audience.
    SendCampaignMessage::dispatchSync($recipient->id);

    expect($recipient->fresh()->status)->toBe('pending')
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(0);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns/{$campaign->id}/resume")
        ->assertOk();

    $campaign->refresh();

    expect($campaign->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->recipients()->where('status', 'sent')->count())->toBe(2)
        ->and($campaign->recipients()->where('status', 'skipped')->count())->toBe(0);
});

it('rejects resuming a campaign that is not paused', function () {
    $ctx = campaignContext();

    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'name' => 'Draft Promo',
        'status' => CampaignStatus::Draft,
        'audience_type' => 'all',
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns/{$campaign->id}/resume")
        ->assertStatus(422);
});

it('targets tag audiences and dynamic segments', function () {
    $ctx = campaignContext();
    $workspace = $ctx['workspace'];

    $tag = $workspace->tags()->create(['name' => 'Lead']);
    $tagged = Contact::factory()->create(['workspace_id' => $workspace->id, 'country' => 'EG']);
    $tagged->tags()->attach($tag->id);
    Contact::factory()->create(['workspace_id' => $workspace->id, 'country' => 'EG']);

    $campaign = Campaign::query()->create([
        'workspace_id' => $workspace->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'name' => 'Tag audience',
        'status' => 'draft',
        'audience_type' => 'tag',
        'audience_config' => ['tag_id' => $tag->id],
    ]);

    $service = app(CampaignService::class);
    expect($service->audienceQuery($campaign)->count())->toBe(1);

    $segment = $workspace->segments()->create([
        'name' => 'Egypt Leads',
        'filters' => [
            'match' => 'all',
            'conditions' => [
                ['source' => 'tag', 'key' => null, 'operator' => 'contains', 'value' => 'Lead'],
                ['source' => 'contact', 'key' => 'country', 'operator' => 'equals', 'value' => 'EG'],
            ],
        ],
    ]);

    $matcher = app(SegmentMatcher::class);
    expect($matcher->query($workspace, $segment)->count())->toBe(1)
        ->and($matcher->query($workspace, $segment)->first()->id)->toBe($tagged->id);
});

it('blocks campaigns for plans without the feature', function () {
    $ctx = createWorkspaceContext(); // no subscription/features

    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'status' => 'approved',
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns", [
            'name' => 'Nope',
            'whatsapp_account_id' => $ctx['account']->id,
            'whatsapp_template_id' => $template->id,
            'audience_type' => 'all',
        ])
        ->assertForbidden();
});
