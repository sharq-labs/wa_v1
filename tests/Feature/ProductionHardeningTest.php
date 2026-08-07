<?php

use App\Enums\AutomationRunStatus;
use App\Enums\TemplateStatus;
use App\Enums\WorkspaceRole;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\Automation\FlowValidator;

it('does not expose automation definitions to viewers', function () {
    $ctx = createWorkspaceContext();
    $viewer = addAgent($ctx['workspace'], 'Read Only Viewer', WorkspaceRole::Viewer);
    $automation = Automation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'draft_definition' => [
            'nodes' => [[
                'id' => 'http',
                'type' => 'http_request',
                'config' => ['auth' => ['type' => 'bearer', 'token' => 'super-secret']],
            ]],
            'edges' => [],
        ],
    ]);

    $this->actingAs($viewer)
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/automations/{$automation->id}")
        ->assertForbidden();
});

it('redacts credentials from automation run step input', function () {
    $ctx = createWorkspaceContext();
    $automation = publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'stop', 'type' => 'stop', 'config' => []],
        ],
        'edges' => [
            ['id' => 'edge', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ]);

    $run = AutomationRun::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'automation_id' => $automation->id,
        'automation_version_id' => $automation->published_version_id,
        'status' => AutomationRunStatus::Running,
        'started_at' => now(),
    ]);

    $step = $run->steps()->create([
        'node_id' => 'http',
        'node_type' => 'http_request',
        'status' => 'executed',
        'input' => [
            'auth' => ['type' => 'bearer', 'token' => 'super-secret'],
            'headers' => [['key' => 'Authorization', 'value' => 'Bearer another-secret']],
            'password' => 'basic-secret',
        ],
    ])->fresh();

    expect($step->input['auth']['token'])->toBe('[REDACTED]')
        ->and($step->input['headers'][0]['value'])->toBe('[REDACTED]')
        ->and($step->input['password'])->toBe('[REDACTED]');
});

it('rejects assigning a conversation to a viewer', function () {
    $ctx = createWorkspaceContext();
    $viewer = addAgent($ctx['workspace'], 'Viewer User', WorkspaceRole::Viewer);
    $message = receiveInboundText($ctx['account'], '201011111111', 'hello');

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$message->conversation_id}/assign", [
            'user_id' => $viewer->id,
        ])
        ->assertStatus(422);
});

it('rejects reply_to ids from another conversation', function () {
    $ctx = createWorkspaceContext();
    $first = receiveInboundText($ctx['account'], '201022222221', 'first');
    $second = receiveInboundText($ctx['account'], '201022222222', 'second');

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$first->conversation_id}/messages/text", [
            'text' => 'reply',
            'reply_to' => $second->id,
        ])
        ->assertUnprocessable();
});

it('does not allow a template from another WhatsApp account in the inbox', function () {
    $ctx = createWorkspaceContext();
    $message = receiveInboundText($ctx['account'], '201033333331', 'hello');
    $otherAccount = WhatsAppAccount::factory()->create(['workspace_id' => $ctx['workspace']->id]);
    $template = WhatsAppTemplate::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $otherAccount->id,
        'status' => TemplateStatus::Approved,
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$message->conversation_id}/messages/template", [
            'template_id' => $template->id,
            'variable_mappings' => [],
        ])
        ->assertNotFound();
});

it('rejects duplicate automation node and edge ids', function () {
    $ctx = createWorkspaceContext();
    $validator = app(FlowValidator::class);

    $errors = $validator->validate($ctx['workspace'], [
        'nodes' => [
            ['id' => 'same', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'same', 'type' => 'send_text', 'config' => ['text' => 'hello']],
        ],
        'edges' => [
            ['id' => 'dup', 'source' => 'same', 'target' => 'same'],
            ['id' => 'dup', 'source' => 'same', 'target' => 'same'],
        ],
    ]);

    expect(collect($errors)->pluck('message'))
        ->toContain('Node ids must be unique.')
        ->toContain('Edge ids must be unique.');
});
