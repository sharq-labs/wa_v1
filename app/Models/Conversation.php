<?php

namespace App\Models;

use App\Enums\AutomationStatus;
use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'whatsapp_account_id',
        'contact_id',
        'assigned_user_id',
        'assigned_team_id',
        'status',
        'automation_status',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
        'opened_at',
        'closed_at',
        'first_agent_reply_at',
    ];

    protected static function booted(): void
    {
        static::updated(function (Conversation $conversation): void {
            if (! $conversation->wasChanged('assigned_user_id') || ! $conversation->assigned_user_id) {
                return;
            }

            $workspace = Workspace::query()->find($conversation->workspace_id);
            $user = User::query()->find($conversation->assigned_user_id);
            if (! $workspace || ! $user) {
                return;
            }

            $contact = Contact::query()->find($conversation->contact_id);
            app(WorkspaceNotificationService::class)->user($workspace, $user, [
                'type' => 'conversation.assigned',
                'title' => __('Conversation assigned to you'),
                'message' => __('You have been assigned a conversation with :contact.', [
                    'contact' => $contact?->full_name ?? __('a customer'),
                ]),
                'url' => url('/inbox?conversation='.$conversation->id),
                'severity' => 'info',
                'meta' => [
                    'conversation_id' => $conversation->id,
                    'contact_id' => $conversation->contact_id,
                ],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'automation_status' => AutomationStatus::class,
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_agent_reply_at' => 'datetime',
        ];
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(AgentTeam::class, 'assigned_team_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class);
    }

    public function isBotActive(): bool
    {
        return $this->automation_status === AutomationStatus::Active;
    }
}
