<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AutomationRun extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'automation_id',
        'automation_version_id',
        'contact_id',
        'conversation_id',
        'trigger_message_id',
        'status',
        'current_node_id',
        'steps_executed',
        'depth',
        'parent_run_id',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AutomationRun $run) {
            $run->uuid ??= (string) Str::uuid();
        });

        static::updated(function (AutomationRun $run): void {
            if (! $run->wasChanged('status') || $run->status !== AutomationRunStatus::Failed) {
                return;
            }

            $workspace = Workspace::query()->find($run->workspace_id);
            if (! $workspace) {
                return;
            }

            $automation = Automation::query()->find($run->automation_id);
            app(WorkspaceNotificationService::class)->managers($workspace, [
                'type' => 'automation.failed',
                'title' => __('Automation run failed'),
                'message' => __(':automation failed: :error', [
                    'automation' => $automation?->name ?? __('Automation'),
                    'error' => mb_strimwidth((string) ($run->error ?: __('Unknown error')), 0, 240, '…'),
                ]),
                'url' => url('/automations/'.$run->automation_id.'/runs'),
                'severity' => 'error',
                'meta' => [
                    'automation_id' => $run->automation_id,
                    'run_uuid' => $run->uuid,
                    'contact_id' => $run->contact_id,
                ],
            ]);
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'automation_version_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class);
    }

    public function waits(): HasMany
    {
        return $this->hasMany(AutomationWait::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(AutomationVariable::class);
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'parent_run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [AutomationRunStatus::Running, AutomationRunStatus::Waiting], true);
    }

    public function getVariable(string $key): ?string
    {
        return $this->variables()->where('key', $key)->value('value');
    }

    public function setVariable(string $key, ?string $value): void
    {
        $this->variables()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, string|null> */
    public function variablesMap(): array
    {
        return $this->variables()->pluck('value', 'key')->all();
    }
}
