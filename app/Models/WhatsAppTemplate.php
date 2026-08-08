<?php

namespace App\Models;

use App\Enums\TemplateStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppTemplate extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'workspace_id',
        'whatsapp_account_id',
        'meta_template_id',
        'name',
        'language',
        'category',
        'status',
        'header_type',
        'header_content',
        'body',
        'footer',
        'buttons',
        'variables',
        'rejection_reason',
        'quality_rating',
        'usage_count',
        'last_synced_at',
    ];

    protected static function booted(): void
    {
        static::updated(function (WhatsAppTemplate $template): void {
            if (! $template->wasChanged('status') || ! in_array($template->status, [
                TemplateStatus::Rejected,
                TemplateStatus::Paused,
                TemplateStatus::Disabled,
            ], true)) {
                return;
            }

            $workspace = Workspace::query()->find($template->workspace_id);
            if (! $workspace) {
                return;
            }

            app(WorkspaceNotificationService::class)->managers($workspace, [
                'type' => 'whatsapp.template_health',
                'title' => __('WhatsApp template needs attention'),
                'message' => __('Template :name is now :status. :reason', [
                    'name' => $template->name,
                    'status' => $template->status->value,
                    'reason' => $template->rejection_reason ?: '',
                ]),
                'url' => url('/templates'),
                'severity' => $template->status === TemplateStatus::Rejected ? 'error' : 'warning',
                'meta' => [
                    'template_id' => $template->id,
                    'status' => $template->status->value,
                ],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TemplateStatus::class,
            'buttons' => 'array',
            'variables' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function isApproved(): bool
    {
        return $this->status === TemplateStatus::Approved;
    }

    /** Number of {{n}} placeholders in the body. */
    public function bodyVariableCount(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->body, $matches);

        return count(array_unique($matches[1] ?? []));
    }
}
