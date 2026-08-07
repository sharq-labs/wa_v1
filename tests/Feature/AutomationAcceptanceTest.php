<?php

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Jobs\ResumeAutomationWait;
use App\Models\AutomationRun;
use App\Models\AutomationWait;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Automation\AutomationEngine;
use App\Services\Conversations\AssignmentService;
use App\Services\Messaging\FakeWhatsAppProvider;
use Illuminate\Support\Facades\Queue;

/**
 * Full Lead Qualification acceptance scenario (spec §72) using the fake provider.
 */
it('runs the lead qualification bot end to end', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];
    $account = $ctx['account'];

    $ahmed = addAgent($workspace, 'Ahmed');
    $sara = addAgent($workspace, 'Sara');

    $sales = $workspace->teams()->create(['name' => 'Sales', 'assignment_strategy' => 'round_robin']);
    $sales->members()->attach([$ahmed->id, $sara->id]);

    foreach ([
        ['name' => 'Company Name', 'key' => 'company_name', 'type' => 'text'],
        ['name' => 'Service', 'key' => 'service', 'type' => 'text'],
        ['name' => 'Budget', 'key' => 'budget', 'type' => 'number'],
    ] as $field) {
        $workspace->customFields()->create($field);
    }
    $workspace->tags()->create(['name' => 'Sales Lead']);

    publishAutomation($workspace, leadQualificationDefinition($sales->id), 'Lead Qualification Bot');

    // ------------------------------------------------------------------
    // 1. Customer sends "عاوز اعرف السعر"
    // ------------------------------------------------------------------
    receiveInboundText($account, '201012345678', 'عاوز اعرف السعر');

    $contact = Contact::query()->where('wa_id', '201012345678')->first();
    $conversation = Conversation::query()->where('contact_id', $contact->id)->first();
    $run = AutomationRun::query()->first();

    expect($contact)->not->toBeNull()
        ->and($conversation)->not->toBeNull()
        ->and(Message::query()->where('direction', 'inbound')->count())->toBe(1)
        ->and($run)->not->toBeNull()
        ->and($run->status)->toBe(AutomationRunStatus::Waiting);

    // Bot sent welcome + company question through the fake provider.
    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    expect($sentTexts)->toContain('أهلاً Test 👋')
        ->and($sentTexts)->toContain('ما اسم شركتك؟');

    // Waiting state is persisted in the database, not memory.
    expect(AutomationWait::query()->where('status', 'pending')->count())->toBe(1);

    // ------------------------------------------------------------------
    // 2. Customer answers the company question
    // ------------------------------------------------------------------
    receiveInboundText($account, '201012345678', 'TechCorp');

    expect($contact->fresh()->customFieldValue('company_name'))->toBe('TechCorp');

    // Buttons were sent and a new wait persisted.
    $interactive = collect(FakeWhatsAppProvider::$sent)->firstWhere('type', 'interactive');
    expect($interactive)->not->toBeNull()
        ->and(AutomationWait::query()->where('status', 'pending')->count())->toBe(1);

    // ------------------------------------------------------------------
    // 3. Customer presses the "Website" button
    // ------------------------------------------------------------------
    receiveInboundText($account, '201012345678', 'Website', replyId: 'btn_website');

    expect($contact->fresh()->customFieldValue('service'))->toBe('Website');

    // Budget question asked.
    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    expect($sentTexts)->toContain('ما الميزانية المتوقعة؟');

    // ------------------------------------------------------------------
    // 4. Invalid budget answer keeps the flow waiting and sends the error
    // ------------------------------------------------------------------
    receiveInboundText($account, '201012345678', 'not a number');

    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    expect($sentTexts)->toContain('من فضلك أدخل الميزانية كرقم.')
        ->and(AutomationRun::query()->first()->status)->toBe(AutomationRunStatus::Waiting);

    // ------------------------------------------------------------------
    // 5. Valid budget completes the flow: tag, assignment, pause, stop
    // ------------------------------------------------------------------
    receiveInboundText($account, '201012345678', '50000');

    $contact = $contact->fresh();
    $conversation = $conversation->fresh();
    $run = AutomationRun::query()->first();

    expect($contact->customFieldValue('budget'))->toBe('50000')
        ->and($contact->tags()->pluck('name'))->toContain('Sales Lead')
        ->and($conversation->assigned_team_id)->toBe($sales->id)
        ->and($conversation->assigned_user_id)->toBe($ahmed->id) // round robin picks first agent
        ->and($conversation->automation_status)->toBe(AutomationStatus::Paused)
        ->and($run->status)->toBe(AutomationRunStatus::Completed);

    // Confirmation sent.
    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    expect($sentTexts)->toContain('شكراً، تم تحويلك لممثل المبيعات.');

    // Full run trace persisted for debugging.
    expect($run->steps()->count())->toBeGreaterThanOrEqual(8);

    // ------------------------------------------------------------------
    // 6. Bot paused: further messages do not trigger automation again
    // ------------------------------------------------------------------
    FakeWhatsAppProvider::reset();
    receiveInboundText($account, '201012345678', 'عاوز اعرف السعر');

    expect(AutomationRun::query()->count())->toBe(1)
        ->and(FakeWhatsAppProvider::$sent)->toBeEmpty();
});

it('takes the FALSE branch for a low budget', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $sales = $workspace->teams()->create(['name' => 'Sales']);
    foreach (['company_name', 'service', 'budget'] as $key) {
        $workspace->customFields()->create(['name' => $key, 'key' => $key, 'type' => 'text']);
    }

    publishAutomation($workspace, leadQualificationDefinition($sales->id));

    receiveInboundText($ctx['account'], '201099887766', 'ما هو السعر؟');
    receiveInboundText($ctx['account'], '201099887766', 'SmallCo');
    receiveInboundText($ctx['account'], '201099887766', 'Other', replyId: 'btn_other');
    receiveInboundText($ctx['account'], '201099887766', '500');

    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    $contact = Contact::query()->where('wa_id', '201099887766')->first();

    expect($sentTexts)->toContain('شكراً لتواصلك معنا!')
        ->and($contact->tags()->count())->toBe(0)
        ->and(Conversation::query()->where('contact_id', $contact->id)->value('assigned_user_id'))->toBeNull()
        ->and(AutomationRun::query()->value('status'))->toBe(AutomationRunStatus::Completed);
});

it('round robin rotates between agents across conversations', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $ahmed = addAgent($workspace, 'Ahmed');
    $sara = addAgent($workspace, 'Sara');
    $sales = $workspace->teams()->create(['name' => 'Sales', 'assignment_strategy' => 'round_robin']);
    $sales->members()->attach([$ahmed->id, $sara->id]);

    $service = app(AssignmentService::class);

    $assignments = [];
    foreach (range(1, 4) as $i) {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
        ]);
        $service->assignToTeam($conversation, $sales);
        $assignments[] = $conversation->fresh()->assigned_user_id;
    }

    expect($assignments)->toBe([$ahmed->id, $sara->id, $ahmed->id, $sara->id]);
});

it('does not run automations twice for a duplicated inbound message', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $sales = $workspace->teams()->create(['name' => 'Sales']);
    foreach (['company_name', 'service', 'budget'] as $key) {
        $workspace->customFields()->create(['name' => $key, 'key' => $key, 'type' => 'text']);
    }
    publishAutomation($workspace, leadQualificationDefinition($sales->id));

    receiveInboundText($ctx['account'], '201510000000', 'price please', providerMessageId: 'wamid.same.1');
    receiveInboundText($ctx['account'], '201510000000', 'price please', providerMessageId: 'wamid.same.1');

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(1)
        ->and(AutomationRun::query()->count())->toBe(1);
});

it('persists delay waits with queued resume jobs instead of sleeping', function () {
    Queue::fake([ResumeAutomationWait::class]);

    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $definition = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'd', 'type' => 'delay', 'config' => ['amount' => 5, 'unit' => 'minutes']],
            ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'after delay']],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 'd'],
            ['id' => 'e2', 'source' => 'd', 'sourceHandle' => 'next', 'target' => 's'],
        ],
    ];

    publishAutomation($workspace, $definition, 'Delay Bot');

    receiveInboundText($ctx['account'], '201520000000', 'hi');

    $wait = AutomationWait::query()->where('wait_type', 'delay')->first();

    expect($wait)->not->toBeNull()
        ->and($wait->resume_at->diffInMinutes(now()))->toBeLessThanOrEqual(5)
        ->and(AutomationRun::query()->value('status'))->toBe(AutomationRunStatus::Waiting);

    Queue::assertPushed(ResumeAutomationWait::class);

    // Resuming the wait continues the flow (simulates the delayed job firing).
    $wait->forceFill(['resume_at' => now()->subSecond()])->save();
    app(AutomationEngine::class)->resumeWait($wait->fresh());

    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);
    expect($sentTexts)->toContain('after delay')
        ->and(AutomationRun::query()->value('status'))->toBe(AutomationRunStatus::Completed);
});

it('picks only the highest priority automation by default', function () {
    $ctx = createWorkspaceContext();
    $workspace = $ctx['workspace'];

    $definitionA = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_keyword', 'config' => ['keywords' => ['hello'], 'match_type' => 'contains']],
            ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'from low priority']],
        ],
        'edges' => [['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 's']],
    ];
    $definitionB = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_keyword', 'config' => ['keywords' => ['hello'], 'match_type' => 'contains']],
            ['id' => 's', 'type' => 'send_text', 'config' => ['text' => 'from high priority']],
        ],
        'edges' => [['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 's']],
    ];

    publishAutomation($workspace, $definitionA, 'Low', priority: 1);
    publishAutomation($workspace, $definitionB, 'High', priority: 10);

    receiveInboundText($ctx['account'], '201530000000', 'hello there');

    $sentTexts = array_map(fn ($s) => $s['payload']['text'] ?? '', FakeWhatsAppProvider::$sent);

    expect($sentTexts)->toContain('from high priority')
        ->and($sentTexts)->not->toContain('from low priority')
        ->and(AutomationRun::query()->count())->toBe(1);
});

it('stops runaway loops at the step limit', function () {
    config(['whatsapp.max_automation_steps' => 10]);

    $ctx = createWorkspaceContext();

    $definition = [
        'nodes' => [
            ['id' => 't', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'a', 'type' => 'send_text', 'config' => ['text' => 'loop']],
            ['id' => 'g', 'type' => 'go_to_node', 'config' => ['target_node_id' => 'a']],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 't', 'sourceHandle' => 'next', 'target' => 'a'],
            ['id' => 'e2', 'source' => 'a', 'sourceHandle' => 'next', 'target' => 'g'],
        ],
    ];

    publishAutomation($ctx['workspace'], $definition, 'Loop Bot');

    receiveInboundText($ctx['account'], '201540000000', 'start');

    $run = AutomationRun::query()->first();

    expect($run->status)->toBe(AutomationRunStatus::Failed)
        ->and($run->error)->toContain('Maximum automation steps');
});
