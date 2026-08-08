<?php

use App\Enums\NodeType;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Services\Automation\AutomationEventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('emits tag events only when published automations listen for the mutation', function () {
    Queue::fake([ProcessAutomationEvent::class]);
    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
    $tag = Tag::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'VIP',
        'color' => '#22c55e',
    ]);

    // With no listener, contact mutations do not create useless queue traffic.
    $contact->tags()->attach($tag->id);
    Queue::assertNotPushed(ProcessAutomationEvent::class);
    $contact->tags()->detach($tag->id);

    publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => NodeType::TriggerTagAdded->value, 'config' => ['tag_id' => $tag->id]],
            ['id' => 'stop', 'type' => NodeType::Stop->value, 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'Tag Listener');

    $contact->tags()->attach($tag->id);

    Queue::assertPushed(ProcessAutomationEvent::class, 1);
    Queue::assertPushed(ProcessAutomationEvent::class, fn ($job) =>
        $job->eventType === NodeType::TriggerTagAdded->value
        && $job->contactId === $contact->id
        && ($job->payload['tag_id'] ?? null) === $tag->id
    );
});

it('emits a field changed event only for real changes with a published listener', function () {
    Queue::fake([ProcessAutomationEvent::class]);
    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
    $field = CustomField::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'Lead Status',
        'key' => 'lead_status',
        'type' => 'text',
    ]);

    publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => NodeType::TriggerFieldChanged->value, 'config' => ['field_key' => 'lead_status']],
            ['id' => 'stop', 'type' => NodeType::Stop->value, 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'Field Listener');

    $contact->setCustomFieldValue($field, 'new');
    $contact->setCustomFieldValue($field, 'new');
    $contact->setCustomFieldValue($field, 'qualified');

    Queue::assertPushed(ProcessAutomationEvent::class, 2);
    Queue::assertPushed(ProcessAutomationEvent::class, fn ($job) =>
        $job->eventType === NodeType::TriggerFieldChanged->value
        && ($job->payload['field_key'] ?? null) === 'lead_status'
        && ($job->payload['old_value'] ?? null) === 'new'
        && ($job->payload['new_value'] ?? null) === 'qualified'
    );
});

it('matches a tag event and starts the published automation', function () {
    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
    $tag = Tag::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'Qualified',
        'color' => '#22c55e',
    ]);
    $field = CustomField::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'Source',
        'key' => 'source',
        'type' => 'text',
    ]);

    publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => NodeType::TriggerTagAdded->value, 'config' => ['tag_id' => $tag->id]],
            ['id' => 'field', 'type' => NodeType::SetCustomField->value, 'config' => ['field_key' => 'source', 'value' => 'tag-trigger']],
            ['id' => 'stop', 'type' => NodeType::Stop->value, 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'field'],
            ['id' => 'e2', 'source' => 'field', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'Tag Trigger Bot');

    $started = app(AutomationEventDispatcher::class)->dispatch(
        $ctx['workspace'],
        NodeType::TriggerTagAdded->value,
        $contact,
        ['tag_id' => $tag->id, 'tag_name' => $tag->name],
    );

    expect($started)->toBe(1)
        ->and($contact->fresh()->customFieldValue($field->key))->toBe('tag-trigger');
});

it('rotates an encrypted webhook secret and accepts only valid signed payloads', function () {
    Queue::fake([ProcessAutomationEvent::class]);
    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'phone_number' => '201234567890',
    ]);

    $automation = publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => NodeType::TriggerWebhook->value, 'config' => []],
            ['id' => 'stop', 'type' => NodeType::Stop->value, 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'Webhook Bot');

    $credentials = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/automations/{$automation->id}/webhook-endpoint/rotate")
        ->assertOk()
        ->json('data');

    expect($credentials['secret'])->not->toBeEmpty()
        ->and($credentials['public_key'])->not->toBeEmpty();

    $raw = json_encode(['phone_number' => $contact->phone_number, 'order_id' => 123], JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $raw, $credentials['secret']);

    $this->call(
        'POST',
        '/api/automation-hooks/'.$credentials['public_key'],
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WHATSFLOW_SIGNATURE' => 'sha256='.$signature,
        ],
        $raw,
    )->assertStatus(202);

    Queue::assertPushed(ProcessAutomationEvent::class, fn ($job) =>
        $job->eventType === NodeType::TriggerWebhook->value
        && $job->contactId === $contact->id
        && ($job->payload['automation_id'] ?? null) === $automation->id
    );

    $this->call(
        'POST',
        '/api/automation-hooks/'.$credentials['public_key'],
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WHATSFLOW_SIGNATURE' => 'sha256=invalid',
        ],
        $raw,
    )->assertStatus(401);
});

it('dispatches a due scheduled automation only once per minute', function () {
    Queue::fake([ProcessAutomationEvent::class]);
    Carbon::setTestNow(Carbon::parse('2026-08-08 09:00:00', 'UTC'));

    $ctx = createWorkspaceContext();
    $ctx['workspace']->update(['timezone' => 'UTC']);
    $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    $automation = publishAutomation($ctx['workspace'], [
        'nodes' => [
            [
                'id' => 'trigger',
                'type' => NodeType::TriggerScheduled->value,
                'config' => [
                    'frequency' => 'daily',
                    'time' => '09:00',
                    'audience_type' => 'contact',
                    'contact_id' => $contact->id,
                ],
            ],
            ['id' => 'stop', 'type' => NodeType::Stop->value, 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'Daily Bot');

    $this->artisan('automation:dispatch-scheduled')->assertExitCode(0);
    $this->artisan('automation:dispatch-scheduled')->assertExitCode(0);

    Queue::assertPushed(ProcessAutomationEvent::class, 1);
    Queue::assertPushed(ProcessAutomationEvent::class, fn ($job) =>
        $job->eventType === NodeType::TriggerScheduled->value
        && $job->contactId === $contact->id
        && ($job->payload['automation_id'] ?? null) === $automation->id
    );

    expect(DB::table('automation_schedule_ticks')->where('automation_id', $automation->id)->count())->toBe(1);

    Carbon::setTestNow();
});
