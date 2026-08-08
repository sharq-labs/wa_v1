<?php

namespace App\Models;

use App\Enums\AutomationState;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Automation extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'status',
        'priority',
        'published_version_id',
        'draft_definition',
        'created_by',
    ];

    // Drafts can contain HTTP credentials. Never expose them through generic
    // model serialization/list endpoints; AutomationController::show returns a
    // dedicated definition field only after manageAutomations authorization.
    protected $hidden = [
        'draft_definition',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationState::class,
            'draft_definition' => 'array',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'published_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->status === AutomationState::Published && $this->published_version_id !== null;
    }
}
