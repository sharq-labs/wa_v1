<?php

use App\Enums\AutomationState;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Services\Automation\FlowValidator;
use App\Services\Messaging\FakeWhatsAppProvider;

it('saves drafts, validates and publishes immutable versions', function () {
    $ctx = createWorkspaceContext();
    $base = "/api/workspaces/{$ctx['workspace']->id}/automations";

    $automation = Automation::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    $definition = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_keyword', 'config' => ['keywords' => ['hi'], 'match_type' => 'contains']],
            ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'Hello!']],
        ],
        'edges' => [['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 's']],
    ];

    $this->actingAs($ctx['user'])
        ->putJson("$base/{$automation->id}/draft", ['definition' => $definition])
        ->assertOk();

    $this->actingAs($ctx['user'])
        ->postJson("$base/{$automation->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.version', 1);

    $automation->refresh();
    expect($automation->status)->toBe(AutomationState::Published)
        ->and($automation->versions()->count())->toBe(1);

    // Editing and republishing creates version 2; version 1 stays untouched.
    $definition['nodes'][1]['config']['text'] = 'Hello v2!';
    $this->actingAs($ctx['user'])->putJson("$base/{$automation->id}/draft", ['definition' => $definition])->assertOk();
    $this->actingAs($ctx['user'])->postJson("$base/{$automation->id}/publish")->assertOk()->assertJsonPath('data.version', 2);

    $v1 = $automation->versions()->where('version', 1)->first();
    expect($v1->definition['nodes'][1]['config']['text'])->toBe('Hello!')
        ->and($automation->fresh()->publishedVersion->version)->toBe(2);
});

it('refuses to publish an invalid flow', function () {
    $ctx = createWorkspaceContext();
    $automation = Automation::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    // Missing trigger + empty text.
    $definition = [
        'nodes' => [['id' => 's', 'type' => 'send_text', 'config' => ['text' => '']]],
        'edges' => [],
    ];

    $this->actingAs($ctx['user'])
        ->putJson("/api/workspaces/{$ctx['workspace']->id}/automations/{$automation->id}/draft", ['definition' => $definition])
        ->assertOk();

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/automations/{$automation->id}/publish")
        ->assertStatus(422);

    expect($automation->fresh()->status)->toBe(AutomationState::Draft);
});

it('rejects flows with waitless loops', function () {
    $ctx = createWorkspaceContext();

    $validator = app(FlowValidator::class);

    $definition = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'a', 'type' => 'send_text', 'config' => ['text' => 'x']],
            ['id' => 'b', 'type' => 'send_text', 'config' => ['text' => 'y']],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 'a'],
            ['id' => 'e2', 'source' => 'a', 'sourceHandle' => 'next', 'target' => 'b'],
            ['id' => 'e3', 'source' => 'b', 'sourceHandle' => 'next', 'target' => 'a'],
        ],
    ];

    $errors = $validator->validate($ctx['workspace'], $definition);

    expect(collect($errors)->pluck('message')->join(' '))->toContain('loop');
});

it('exposes the run timeline for debugging', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $definition = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'traced']],
        ],
        'edges' => [['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 's']],
    ];

    $automation = publishAutomation($workspace, $definition, 'Trace Bot');

    receiveInboundText($ctx['account'], '201560000000', 'anything');

    $run = AutomationRun::query()->first();

    $response = $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$workspace->id}/automations/{$automation->id}/runs/{$run->uuid}")
        ->assertOk();

    $steps = collect($response->json('data.steps'));

    expect($steps->pluck('node_type'))->toContain('trigger_incoming_message', 'send_text');
});

it('runs the simulator without touching the provider', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $automation = Automation::factory()->create([
        'workspace_id' => $workspace->id,
        'draft_definition' => [
            'nodes' => [
                ['id' => 't', 'type' => 'trigger_keyword', 'config' => ['keywords' => ['hi'], 'match_type' => 'contains']],
                ['id' => 'q', 'type' => 'ask_question', 'config' => ['question' => 'Your name?', 'save_to' => 'variables.name']],
                ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'Welcome {{variables.name}}!']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 'q'],
                ['id' => 'e2', 'source' => 'q', 'sourceHandle' => 'next', 'target' => 's'],
            ],
        ],
    ]);

    $base = "/api/workspaces/{$workspace->id}/automations/{$automation->id}";

    $start = $this->actingAs($ctx['user'])->postJson("$base/simulate/start")->assertOk();
    $sessionId = $start->json('data.session_id');

    $step1 = $this->actingAs($ctx['user'])
        ->postJson("$base/simulate/message", ['session_id' => $sessionId, 'text' => 'hi there'])
        ->assertOk();

    expect($step1->json('data.status'))->toBe('waiting_reply')
        ->and(collect($step1->json('data.transcript'))->pluck('text'))->toContain('Your name?');

    $step2 = $this->actingAs($ctx['user'])
        ->postJson("$base/simulate/message", ['session_id' => $sessionId, 'text' => 'Omar'])
        ->assertOk();

    expect($step2->json('data.status'))->toBe('completed')
        ->and(collect($step2->json('data.transcript'))->pluck('text'))->toContain('Welcome Omar!')
        ->and($step2->json('data.variables.name'))->toBe('Omar')
        ->and(FakeWhatsAppProvider::$sent)->toBeEmpty()
        ->and(Message::query()->count())->toBe(0);
});
