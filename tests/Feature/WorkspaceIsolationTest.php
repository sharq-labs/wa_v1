<?php

use App\Enums\WorkspaceRole;
use App\Models\Contact;

it('prevents non-members from accessing another workspace', function () {
    $a = createWorkspaceContext();
    $b = createWorkspaceContext();

    $this->actingAs($b['user'])
        ->getJson("/api/workspaces/{$a['workspace']->id}/contacts")
        ->assertForbidden();
});

it('prevents members from reading contacts of other workspaces through id guessing', function () {
    $a = createWorkspaceContext();
    $b = createWorkspaceContext();

    $foreignContact = Contact::factory()->create(['workspace_id' => $b['workspace']->id]);

    $this->actingAs($a['user'])
        ->getJson("/api/workspaces/{$a['workspace']->id}/contacts/{$foreignContact->id}")
        ->assertNotFound();
});

it('scopes contact lists to the workspace', function () {
    $a = createWorkspaceContext();
    $b = createWorkspaceContext();

    Contact::factory()->count(3)->create(['workspace_id' => $a['workspace']->id]);
    Contact::factory()->count(2)->create(['workspace_id' => $b['workspace']->id]);

    $response = $this->actingAs($a['user'])
        ->getJson("/api/workspaces/{$a['workspace']->id}/contacts")
        ->assertOk();

    expect($response->json('data.meta.total'))->toBe(3);
});

it('denies viewers from managing automations', function () {
    $ctx = createWorkspaceContext();
    $viewer = addAgent($ctx['workspace'], 'Viewer User', WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/automations", ['name' => 'Bot'])
        ->assertForbidden();
});

it('denies agents from managing members', function () {
    $ctx = createWorkspaceContext();
    $agent = addAgent($ctx['workspace'], 'Agent User', WorkspaceRole::Agent);
    $other = addAgent($ctx['workspace'], 'Other User', WorkspaceRole::Agent);

    $this->actingAs($agent)
        ->putJson("/api/workspaces/{$ctx['workspace']->id}/members/{$other->id}/role", ['role' => 'admin'])
        ->assertForbidden();
});

it('allows admins to invite members', function () {
    $ctx = createWorkspaceContext();
    $admin = addAgent($ctx['workspace'], 'Admin User', WorkspaceRole::Admin);

    // This test is about authorization, not paid agent-seat limits. A viewer
    // does not consume an agent seat, so the permission check stays isolated.
    $this->actingAs($admin)
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'viewer',
        ])
        ->assertCreated();

    expect($ctx['workspace']->invitations()->where('email', 'invitee@example.com')->exists())->toBeTrue();
});

it('never trusts workspace_id from the request body', function () {
    $a = createWorkspaceContext();
    $b = createWorkspaceContext();

    $this->actingAs($a['user'])
        ->postJson("/api/workspaces/{$a['workspace']->id}/contacts", [
            'phone_number' => '201099999999',
            'workspace_id' => $b['workspace']->id,
        ])
        ->assertCreated();

    expect(Contact::query()->where('phone_number', '201099999999')->first()->workspace_id)
        ->toBe($a['workspace']->id);
});
