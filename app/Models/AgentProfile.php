<?php

namespace App\Models;

use App\Enums\AgentAvailability;
use App\Enums\AgentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'availability',
        'status',
        'maximum_conversations',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'availability' => AgentAvailability::class,
            'status' => AgentStatus::class,
            'last_active_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAssignable(): bool
    {
        return $this->availability === AgentAvailability::Available;
    }
}
