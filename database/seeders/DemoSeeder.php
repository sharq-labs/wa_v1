<?php

namespace Database\Seeders;

use App\Enums\AutomationState;
use App\Enums\AutomationStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\WorkspaceRole;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // Users
        // ------------------------------------------------------------------
        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'admin@platform.test'],
            ['name' => 'Platform Admin', 'password' => 'password', 'email_verified_at' => now(), 'is_super_admin' => true],
        );

        $owner = User::query()->updateOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Demo Admin', 'password' => 'password', 'email_verified_at' => now()],
        );

        $ahmed = User::query()->updateOrCreate(
            ['email' => 'ahmed@demo.test'],
            ['name' => 'Ahmed', 'password' => 'password', 'email_verified_at' => now()],
        );

        $sara = User::query()->updateOrCreate(
            ['email' => 'sara@demo.test'],
            ['name' => 'Sara', 'password' => 'password', 'email_verified_at' => now()],
        );

        // ------------------------------------------------------------------
        // Workspace
        // ------------------------------------------------------------------
        $workspace = Workspace::query()->updateOrCreate(
            ['slug' => 'demo-company'],
            [
                'owner_id' => $owner->id,
                'name' => 'Demo Company',
                'country' => 'EG',
                'timezone' => 'Africa/Cairo',
                'currency' => 'EGP',
                'locale' => 'en',
                'status' => 'active',
                'onboarded_at' => now(),
                'settings' => [
                    'automation' => ['allow_multiple' => false, 'fallback_mode' => 'none'],
                ],
            ],
        );

        $workspace->users()->syncWithoutDetaching([
            $owner->id => ['role' => WorkspaceRole::Owner->value, 'joined_at' => now()],
            $ahmed->id => ['role' => WorkspaceRole::Agent->value, 'joined_at' => now()],
            $sara->id => ['role' => WorkspaceRole::Agent->value, 'joined_at' => now()],
        ]);

        foreach ([$owner, $ahmed, $sara] as $user) {
            $workspace->agentProfiles()->updateOrCreate(
                ['user_id' => $user->id],
                ['availability' => 'available', 'status' => 'online', 'maximum_conversations' => 20],
            );
            if (! $user->current_workspace_id) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }
        }

        // Subscription on Pro so campaigns are demoable.
        $pro = Plan::query()->where('slug', 'pro')->first();
        if ($pro && ! $workspace->subscription()->exists()) {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $pro->id,
                'provider' => 'manual',
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        // ------------------------------------------------------------------
        // Team
        // ------------------------------------------------------------------
        $sales = $workspace->teams()->updateOrCreate(
            ['name' => 'Sales'],
            ['color' => '#16a34a', 'assignment_strategy' => 'round_robin'],
        );
        $sales->members()->syncWithoutDetaching([$ahmed->id, $sara->id]);

        // ------------------------------------------------------------------
        // WhatsApp account (fake provider)
        // ------------------------------------------------------------------
        $account = $workspace->whatsappAccounts()->updateOrCreate(
            ['phone_number_id' => 'demo-phone-001'],
            [
                'provider' => 'fake',
                'meta_business_id' => 'demo-business',
                'waba_id' => 'demo-waba',
                'display_phone_number' => '+20 100 000 0000',
                'verified_name' => 'Demo Company',
                'access_token' => 'demo-token-'.Str::random(24),
                'quality_rating' => 'GREEN',
                'messaging_limit' => 'TIER_1K',
                'status' => 'connected',
                'last_sync_at' => now(),
            ],
        );

        // ------------------------------------------------------------------
        // Tags & custom fields
        // ------------------------------------------------------------------
        $tags = [];
        foreach ([
            ['name' => 'Lead', 'color' => '#3b82f6'],
            ['name' => 'Customer', 'color' => '#16a34a'],
            ['name' => 'VIP', 'color' => '#f59e0b'],
            ['name' => 'Sales Lead', 'color' => '#dc2626'],
        ] as $tag) {
            $tags[$tag['name']] = $workspace->tags()->updateOrCreate(['name' => $tag['name']], $tag);
        }

        foreach ([
            ['name' => 'Company Name', 'key' => 'company_name', 'type' => 'text'],
            ['name' => 'Service', 'key' => 'service', 'type' => 'text'],
            ['name' => 'Budget', 'key' => 'budget', 'type' => 'number'],
        ] as $field) {
            $workspace->customFields()->updateOrCreate(['key' => $field['key']], $field);
        }

        // ------------------------------------------------------------------
        // Contacts
        // ------------------------------------------------------------------
        $contacts = [
            ['first_name' => 'Ahmed', 'last_name' => 'Ali', 'phone_number' => '201001111111'],
            ['first_name' => 'Mohamed', 'last_name' => 'Hassan', 'phone_number' => '201002222222'],
            ['first_name' => 'Sara', 'last_name' => 'Ahmed', 'phone_number' => '201003333333'],
        ];

        foreach ($contacts as $data) {
            $contact = $workspace->contacts()->updateOrCreate(
                ['phone_number' => $data['phone_number']],
                $data + [
                    'wa_id' => $data['phone_number'],
                    'whatsapp_account_id' => $account->id,
                    'status' => 'active',
                    'opt_in_status' => 'opted_in',
                ],
            );
            $contact->tags()->syncWithoutDetaching([$tags['Lead']->id]);
        }

        // ------------------------------------------------------------------
        // Fake approved templates
        // ------------------------------------------------------------------
        $workspace->templates()->updateOrCreate(
            ['whatsapp_account_id' => $account->id, 'name' => 'sales_followup', 'language' => 'en'],
            [
                'meta_template_id' => 'fake_sales_followup',
                'category' => 'UTILITY',
                'status' => 'approved',
                'body' => 'Hello {{1}}, we are following up regarding {{2}}.',
                'variables' => ['1' => 'Ahmed', '2' => 'Website'],
                'last_synced_at' => now(),
            ],
        );

        $workspace->templates()->updateOrCreate(
            ['whatsapp_account_id' => $account->id, 'name' => 'welcome_offer', 'language' => 'ar'],
            [
                'meta_template_id' => 'fake_welcome_offer',
                'category' => 'MARKETING',
                'status' => 'approved',
                'body' => 'أهلاً {{1}}! لدينا عرض خاص لك هذا الأسبوع.',
                'footer' => 'Demo Company',
                'variables' => ['1' => 'أحمد'],
                'last_synced_at' => now(),
            ],
        );

        // ------------------------------------------------------------------
        // Lead Qualification Bot (published)
        // ------------------------------------------------------------------
        $this->seedLeadQualificationBot($workspace, $owner, $sales->id);

        // ------------------------------------------------------------------
        // Sample inbox traffic — without it the inbox, dashboard and analytics
        // all render as empty states and the app cannot be evaluated.
        // ------------------------------------------------------------------
        $this->seedConversations($workspace, $account, [$ahmed, $sara]);
    }

    /**
     * Three conversations in different states: one live bot hand-off, one
     * assigned and answered, one closed. Idempotent — keyed on the contact.
     *
     * @param  array<int, User>  $agents
     */
    protected function seedConversations(Workspace $workspace, WhatsAppAccount $account, array $agents): void
    {
        $scripts = [
            [
                'phone' => '201001111111',
                'status' => ConversationStatus::Open,
                'automation_status' => AutomationStatus::Paused,
                'agent' => $agents[0],
                'unread' => 2,
                'messages' => [
                    ['in', 'عايز أعرف سعر الباقات', 190],
                    ['bot', 'أهلاً أحمد 👋 تحت أمرك، ممكن اسم الشركة؟', 188],
                    ['in', 'شركة النور للتجارة', 185],
                    ['bot', 'تمام. ميزانيتك التقريبية كام؟', 184],
                    ['in', '15000', 180],
                    ['agent', 'أهلاً أستاذ أحمد، معاك أحمد من فريق المبيعات. هبعتلك عرض مفصّل حالاً.', 120],
                    ['in', 'تمام في انتظارك', 45],
                    ['in', 'لو في خصم للدفع السنوي يبقى أفضل', 12],
                ],
            ],
            [
                'phone' => '201002222222',
                'status' => ConversationStatus::Open,
                'automation_status' => AutomationStatus::Active,
                'agent' => null,
                'unread' => 1,
                'messages' => [
                    ['in', 'السلام عليكم، الشحن بيوصل الإسكندرية؟', 60],
                    ['bot', 'وعليكم السلام 👋 أيوة بنشحن لكل المحافظات.', 59],
                    ['in', 'والتوصيل ياخد كام يوم؟', 25],
                ],
            ],
            [
                'phone' => '201003333333',
                'status' => ConversationStatus::Closed,
                'automation_status' => AutomationStatus::Active,
                'agent' => $agents[1],
                'unread' => 0,
                'messages' => [
                    ['in', 'ممكن فاتورة الطلب الأخير؟', 2880],
                    ['agent', 'اتبعتت على البريد حالاً ✅', 2875],
                    ['in', 'شكراً جزيلاً', 2870],
                ],
            ],
        ];

        foreach ($scripts as $script) {
            $contact = $workspace->contacts()->where('phone_number', $script['phone'])->first();

            if (! $contact) {
                continue;
            }

            $conversation = Conversation::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'whatsapp_account_id' => $account->id,
                    'contact_id' => $contact->id,
                ],
                [
                    'assigned_user_id' => $script['agent']?->id,
                    'status' => $script['status'],
                    'automation_status' => $script['automation_status'],
                    'unread_count' => $script['unread'],
                    'opened_at' => now()->subMinutes($script['messages'][0][2]),
                    'closed_at' => $script['status'] === ConversationStatus::Closed ? now()->subMinutes(2860) : null,
                ],
            );

            // Rebuild the transcript so re-running the seeder never duplicates it.
            $conversation->messages()->delete();

            $firstAgentReplyAt = null;

            foreach ($script['messages'] as [$who, $text, $minutesAgo]) {
                $at = now()->subMinutes($minutesAgo);
                $inbound = $who === 'in';

                if ($who === 'agent' && $firstAgentReplyAt === null) {
                    $firstAgentReplyAt = $at;
                }

                Message::query()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'whatsapp_account_id' => $account->id,
                    'sender_user_id' => $who === 'agent' ? $script['agent']?->id : null,
                    'provider_message_id' => 'demo-'.Str::random(20),
                    'direction' => $inbound ? MessageDirection::Inbound : MessageDirection::Outbound,
                    'sender_type' => match ($who) {
                        'in' => MessageSenderType::Contact,
                        'bot' => MessageSenderType::Bot,
                        default => MessageSenderType::Agent,
                    },
                    'message_type' => MessageType::Text,
                    'content' => $text,
                    'status' => $inbound ? MessageStatus::Received : MessageStatus::Read,
                    'sent_at' => $at,
                    'delivered_at' => $inbound ? null : $at,
                    'read_at' => $inbound ? null : $at->copy()->addMinute(),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $last = end($script['messages']);
            $lastInbound = collect($script['messages'])->last(fn ($m) => $m[0] === 'in');

            $conversation->update([
                'last_message_at' => now()->subMinutes($last[2]),
                'last_inbound_at' => $lastInbound ? now()->subMinutes($lastInbound[2]) : null,
                'first_agent_reply_at' => $firstAgentReplyAt,
            ]);
        }
    }

    protected function seedLeadQualificationBot(Workspace $workspace, User $owner, int $salesTeamId): void
    {
        $definition = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger_keyword', 'position' => ['x' => 80, 'y' => 40],
                    'config' => ['keywords' => ['سعر', 'price'], 'match_type' => 'contains']],
                ['id' => 'welcome', 'type' => 'send_text', 'position' => ['x' => 80, 'y' => 180],
                    'config' => ['text' => 'أهلاً {{contact.first_name}} 👋']],
                ['id' => 'ask_company', 'type' => 'ask_question', 'position' => ['x' => 80, 'y' => 320],
                    'config' => ['question' => 'ما اسم شركتك؟', 'save_to' => 'custom.company_name', 'validation' => 'text']],
                ['id' => 'service_buttons', 'type' => 'send_buttons', 'position' => ['x' => 80, 'y' => 460],
                    'config' => [
                        'body' => 'ما الخدمة التي تهمك؟',
                        'buttons' => [
                            ['id' => 'btn_website', 'title' => 'Website'],
                            ['id' => 'btn_mobile', 'title' => 'Mobile App'],
                            ['id' => 'btn_other', 'title' => 'Other'],
                        ],
                        'save_to' => 'custom.service',
                    ]],
                ['id' => 'ask_budget', 'type' => 'ask_question', 'position' => ['x' => 80, 'y' => 600],
                    'config' => [
                        'question' => 'ما الميزانية المتوقعة؟',
                        'save_to' => 'custom.budget',
                        'validation' => 'number',
                        'error_message' => 'من فضلك أدخل الميزانية كرقم.',
                    ]],
                ['id' => 'budget_check', 'type' => 'condition', 'position' => ['x' => 80, 'y' => 740],
                    'config' => [
                        'match' => 'all',
                        'conditions' => [
                            ['source' => 'custom', 'key' => 'budget', 'operator' => 'greater_or_equal', 'value' => '10000'],
                        ],
                    ]],
                ['id' => 'tag_lead', 'type' => 'add_tag', 'position' => ['x' => -120, 'y' => 880],
                    'config' => ['tag_name' => 'Sales Lead']],
                ['id' => 'assign_sales', 'type' => 'assign_agent', 'position' => ['x' => -120, 'y' => 1020],
                    'config' => ['team_id' => $salesTeamId, 'strategy' => 'round_robin']],
                ['id' => 'thanks', 'type' => 'send_text', 'position' => ['x' => -120, 'y' => 1160],
                    'config' => ['text' => 'شكراً، تم تحويلك لممثل المبيعات.']],
                ['id' => 'pause', 'type' => 'pause_bot', 'position' => ['x' => -120, 'y' => 1300], 'config' => []],
                ['id' => 'low_budget_msg', 'type' => 'send_text', 'position' => ['x' => 280, 'y' => 880],
                    'config' => ['text' => 'شكراً لتواصلك معنا! سنرسل لك عروضنا المناسبة قريباً.']],
                ['id' => 'stop', 'type' => 'stop', 'position' => ['x' => 80, 'y' => 1440], 'config' => []],
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

        $automation = Automation::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Lead Qualification Bot'],
            [
                'description' => 'Qualifies price enquiries, collects company/service/budget and hands off to Sales.',
                'status' => AutomationState::Published,
                'priority' => 10,
                'created_by' => $owner->id,
                'draft_definition' => $definition,
            ],
        );

        if (! $automation->published_version_id) {
            $version = $automation->versions()->create([
                'version' => 1,
                'definition' => $definition,
                'published_by' => $owner->id,
                'published_at' => now(),
            ]);

            foreach ($definition['nodes'] as $node) {
                $version->nodes()->create([
                    'node_id' => $node['id'],
                    'type' => $node['type'],
                    'config' => $node['config'] ?? null,
                    'position' => $node['position'] ?? null,
                ]);
            }

            foreach ($definition['edges'] as $edge) {
                $version->edges()->create([
                    'edge_id' => $edge['id'],
                    'source_node_id' => $edge['source'],
                    'source_handle' => $edge['sourceHandle'] ?? null,
                    'target_node_id' => $edge['target'],
                ]);
            }

            $automation->update(['published_version_id' => $version->id]);
        }
    }
}
