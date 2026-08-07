<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWait extends Model
{
    use BelongsToWorkspace, HasFactory;

    public const TYPE_REPLY = 'reply';

    public const TYPE_DELAY = 'delay';

    public const TYPE_UNTIL = 'until';

    protected $fillable = [
        'workspace_id',
        'automation_run_id',
        'conversation_id',
        'node_id',
        'wait_type',
        'config',
        'invalid_attempts',
        'resume_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'resume_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
