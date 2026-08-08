<?php

use App\Enums\WorkspaceRole;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\EntitlementsService;

function subscribeTo(array $ctx, array $features): void
{
    $plan = Plan::query()->create(['name' => 'Limited', 'slug' => 'limited-'.uniqid(), 'is_active' => true]);

    foreach ($features as $key => $value) {
        $plan->features()->create(['key' => $key, 'value' => $value]);
    }

    Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);
}

it('enforces the WhatsApp number limit', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['whatsapp_numbers' => '1']);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/whatsapp-accounts/connect-fake")
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('enforces the agent seat limit when inviting inbox users', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['agents' => '2']);
    addAgent($ctx['workspace'], 'Second Seat', WorkspaceRole::Admin);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/invitations", [
            'email' => 'third-agent@example.com',
            'role' => 'agent',
        ])
        ->assertForbidden()
        ->assertJsonPath('success', false);

    // Viewer access does not consume an inbox/agent seat.
    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/invitations", [
            'email' => 'viewer@example.com',
            'role' => 'viewer',
        ])
        ->assertCreated();
});

it('enforces the contact limit with a clear upgrade message', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['contacts' => '1']);

    Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/contacts", ['phone_number' => '201234500000'])
        ->assertForbidden()
        ->assertJsonFragment(['success' => false]);
});

it('enforces the automation limit', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['automations' => '1']);

    Automation::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/automations", ['name' => 'Second Bot'])
        ->assertForbidden();
});

it('treats unlimited as no limit', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['automations' => 'unlimited']);

    foreach (range(1, 3) as $i) {
        $this->actingAs($ctx['user'])
            ->postJson("/api/workspaces/{$ctx['workspace']->id}/automations", ['name' => "Bot $i"])
            ->assertCreated();
    }
});

it('separates platform subscription from Meta usage in the billing summary', function () {
    $ctx = createWorkspaceContext();
    subscribeTo($ctx, ['whatsapp_numbers' => '3']);

    $response = $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/billing/summary")
        ->assertOk();

    expect($response->json('data.platform.plan'))->not->toBeNull()
        ->and($response->json('data.meta_usage.billing_owner'))->toBe('meta');
});

it('does not grant paid plan features after the subscription period ends', function () {
    $ctx = createWorkspaceContext();
    $plan = Plan::query()->create([
        'name' => 'Expired Pro',
        'slug' => 'expired-pro-'.uniqid(),
        'is_active' => true,
    ]);
    $plan->features()->create(['key' => 'campaigns', 'value' => 'true']);
    $plan->features()->create(['key' => 'whatsapp_numbers', 'value' => '9']);

    Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subSecond(),
    ]);

    $workspace = $ctx['workspace']->fresh();
    $entitlements = app(EntitlementsService::class);

    expect($workspace->subscription->isActive())->toBeFalse()
        ->and($entitlements->hasFeature($workspace, 'campaigns'))->toBeFalse()
        ->and($entitlements->limit($workspace, 'whatsapp_numbers', 1))->toBe(1);

    $response = $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$workspace->id}/billing/summary")
        ->assertOk();

    expect($response->json('data.platform.subscription.entitled'))->toBeFalse();
});

it('does not grant paid plan features after a subscription is cancelled', function () {
    $ctx = createWorkspaceContext();
    $plan = Plan::query()->create([
        'name' => 'Cancelled Pro',
        'slug' => 'cancelled-pro-'.uniqid(),
        'is_active' => true,
    ]);
    $plan->features()->create(['key' => 'api_access', 'value' => 'true']);

    Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'cancelled',
        'billing_cycle' => 'monthly',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'cancelled_at' => now(),
    ]);

    $workspace = $ctx['workspace']->fresh();
    $entitlements = app(EntitlementsService::class);

    expect($workspace->subscription->isActive())->toBeFalse()
        ->and($entitlements->canUseApi($workspace))->toBeFalse();
});
