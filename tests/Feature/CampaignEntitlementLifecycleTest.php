<?php

use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaign;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WhatsAppTemplate;
use App\Services\Billing\EntitlementsService;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\FakeWhatsAppProvider;

function campaignLifecycleContext(): array
{
    $ctx = createWorkspaceContext();
    $plan = Plan::query()->create([
        'name' => 'Campaign Lifecycle Pro',
        'slug' => 'campaign-lifecycle-'.uniqid(),
        'is_active' => true,
    ]);
    $plan->features()->createMany([
        ['key' => 'campaigns', 'value' => 'true'],
        ['key' => 'contacts', 'value' => 'unlimited'],
    ]);
    $subscription = Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
    ]);
    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'status' => 'approved',
        'body' => 'Hello',
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'opt_in_status' => 'opted_in',
    ]);

    return $ctx + compact('subscription', 'template', 'contact');
}

it('does not schedule an old draft after campaign entitlement is lost', function () {
    $ctx = campaignLifecycleContext();
    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Old Draft',
        'status' => CampaignStatus::Draft,
        'audience_type' => 'all',
    ]);

    $ctx['subscription']->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/campaigns/{$campaign->id}/schedule")
        ->assertForbidden();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft)
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(0);
});

it('pauses a queued campaign when entitlement disappears before processing', function () {
    $ctx = campaignLifecycleContext();
    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Queued Before Expiry',
        'status' => CampaignStatus::Scheduled,
        'audience_type' => 'all',
    ]);

    $ctx['subscription']->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    (new ProcessCampaign($campaign->id))->handle(
        app(CampaignService::class),
        app(EntitlementsService::class),
    );

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->recipients()->count())->toBe(0)
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(0);
});

it('does not send already queued recipient jobs after entitlement disappears', function () {
    $ctx = campaignLifecycleContext();
    $campaign = Campaign::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'whatsapp_template_id' => $ctx['template']->id,
        'created_by' => $ctx['user']->id,
        'name' => 'Delayed Send',
        'status' => CampaignStatus::Processing,
        'audience_type' => 'all',
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => $ctx['contact']->id,
        'status' => 'pending',
    ]);

    $ctx['subscription']->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    SendCampaignMessage::dispatchSync($recipient->id);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($recipient->fresh()->status)->toBe('pending')
        ->and(FakeWhatsAppProvider::$sent)->toHaveCount(0);
});
