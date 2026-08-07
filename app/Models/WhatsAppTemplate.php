<?php

namespace App\Models;

use App\Enums\TemplateStatus;
use App\Models\Concerns\BelongsToWorkspace;
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
