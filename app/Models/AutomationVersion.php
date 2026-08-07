<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_id',
        'version',
        'definition',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(AutomationEdge::class);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitionNodes(): array
    {
        return $this->definition['nodes'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function definitionEdges(): array
    {
        return $this->definition['edges'] ?? [];
    }

    public function findNode(string $nodeId): ?array
    {
        foreach ($this->definitionNodes() as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }
}
