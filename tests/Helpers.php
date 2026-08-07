<?php

use App\Enums\AutomationState;
use App\Enums\WorkspaceRole;
use App\Models\Automation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use App\Services\Messaging\FakeWhatsAppProvider;
use App\Services\Messaging\InboundMessageService;
use Illuminate\Support\Str;

/**
 * Creates a user + workspace + connected fake WhatsApp account.
 *
 * @return array{user: User, workspace: Workspace, account: WhatsAppAccount}
 */
function createWorkspaceContext(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

    $workspace->users()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);
    $workspace->agentProfiles()->create([
        'user_id' => $user->id,
        'availability' => 'available',
        'status' => 'online',
    ]);

    $account = WhatsAppAccount::factory()->create(['workspace_id' => $workspace->id]);

    FakeWhatsAppProvider::reset();

    return ['user' => $user, 'workspace' => $workspace, 'account' => $account];
}

/**
 * Adds an agent member (with profile) to a workspace.
 */
function addAgent(Workspace $workspace, string $name, WorkspaceRole $role = WorkspaceRole::Agent): User
{
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);

    $workspace->users()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);
    $workspace->agentProfiles()->create([
        'user_id' => $user->id,
        'availability' => 'available',
        'status' => 'online',
    ]);

    return $user;
}

/**
 * Simulates an inbound WhatsApp text message through the ingestion pipeline
 * (as the webhook processor would after verification).
 */
function receiveInboundText(WhatsAppAccount $account, string $waId, string $text, ?string $providerMessageId = null, ?string $replyId = null): ?Message
{
    $payload = [
        'provider_message_id' => $providerMessageId ?? 'wamid.test.'.Str::uuid(),
        'wa_id' => $waId,
        'phone_number' => $waId,
        'profile_name' => 'Test Customer',
        'type' => 'text',
        'text' => $text,
    ];

    if ($replyId !== null) {
        $payload['payload'] = ['reply_id' => $replyId];
    }

    return app(InboundMessageService::class)->ingest($account, $payload);
}

/**
 * Builds the Lead Qualification flow definition used by acceptance tests.
 */
function leadQualificationDefinition(int $salesTeamId): array
{
    return [
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger_keyword',
                'config' => ['keywords' => ['سعر', 'price'], 'match_type' => 'contains']],
            ['id' => 'welcome', 'type' => 'send_text',
                'config' => ['text' => 'أهلاً {{contact.first_name}} 👋']],
            ['id' => 'ask_company', 'type' => 'ask_question',
                'config' => ['question' => 'ما اسم شركتك؟', 'save_to' => 'custom.company_name', 'validation' => 'text']],
            ['id' => 'service_buttons', 'type' => 'send_buttons',
                'config' => [
                    'body' => 'ما الخدمة التي تهمك؟',
                    'buttons' => [
                        ['id' => 'btn_website', 'title' => 'Website'],
                        ['id' => 'btn_mobile', 'title' => 'Mobile App'],
                        ['id' => 'btn_other', 'title' => 'Other'],
                    ],
                    'save_to' => 'custom.service',
                ]],
            ['id' => 'ask_budget', 'type' => 'ask_question',
                'config' => [
                    'question' => 'ما الميزانية المتوقعة؟',
                    'save_to' => 'custom.budget',
                    'validation' => 'number',
                    'error_message' => 'من فضلك أدخل الميزانية كرقم.',
                ]],
            ['id' => 'budget_check', 'type' => 'condition',
                'config' => [
                    'match' => 'all',
                    'conditions' => [
                        ['source' => 'custom', 'key' => 'budget', 'operator' => 'greater_or_equal', 'value' => '10000'],
                    ],
                ]],
            ['id' => 'tag_lead', 'type' => 'add_tag', 'config' => ['tag_name' => 'Sales Lead']],
            ['id' => 'assign_sales', 'type' => 'assign_agent',
                'config' => ['team_id' => $salesTeamId, 'strategy' => 'round_robin']],
            ['id' => 'thanks', 'type' => 'send_text', 'config' => ['text' => 'شكراً، تم تحويلك لممثل المبيعات.']],
            ['id' => 'pause', 'type' => 'pause_bot', 'config' => []],
            ['id' => 'low_budget_msg', 'type' => 'send_text', 'config' => ['text' => 'شكراً لتواصلك معنا!']],
            ['id' => 'stop', 'type' => 'stop', 'config' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'welcome'],
            ['id' => 'e2', 'source' => 'welcome', 'sourceHandle' => 'next', 'target' => 'ask_company'],
            ['id' => 'e3', 'source' => 'ask_company', 'sourceHandle' => 'next', 'target' => 'service_buttons'],
            ['id' => 'e4', 'source' => 'service_buttons', 'sourceHandle' => 'btn_website', 'target' => 'ask_budget'],
            ['id' => 'e5', 'source' => 'service_buttons', 'sourceHandle' => 'btn_mobile', 'target' => 'ask_budget'],
            ['id' => 'e6', 'source' => 'service_buttons', 'sourceHandle' => 'btn_other', 'target' => 'ask_budget'],
            ['id' => 'e7', 'source' => 'ask_budget', 'sourceHandle' => 'next', 'target' => 'budget_check'],
            ['id' => 'e8', 'source' => 'budget_check', 'sourceHandle' => 'true', 'target' => 'tag_lead'],
            ['id' => 'e9', 'source' => 'budget_check', 'sourceHandle' => 'false', 'target' => 'low_budget_msg'],
            ['id' => 'e10', 'source' => 'tag_lead', 'sourceHandle' => 'next', 'target' => 'assign_sales'],
            ['id' => 'e11', 'source' => 'assign_sales', 'sourceHandle' => 'next', 'target' => 'thanks'],
            ['id' => 'e12', 'source' => 'thanks', 'sourceHandle' => 'next', 'target' => 'pause'],
            ['id' => 'e13', 'source' => 'pause', 'sourceHandle' => 'next', 'target' => 'stop'],
            ['id' => 'e14', 'source' => 'low_budget_msg', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ];
}

/**
 * Publishes an automation with the given definition on a workspace.
 */
function publishAutomation(Workspace $workspace, array $definition, string $name = 'Test Bot', int $priority = 0): Automation
{
    $automation = Automation::query()->create([
        'workspace_id' => $workspace->id,
        'name' => $name,
        'status' => AutomationState::Published,
        'priority' => $priority,
        'draft_definition' => $definition,
    ]);

    $version = $automation->versions()->create([
        'version' => 1,
        'definition' => $definition,
        'published_at' => now(),
    ]);

    $automation->update(['published_version_id' => $version->id]);

    return $automation->fresh('publishedVersion');
}
