<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationEdge extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_version_id',
        'edge_id',
        'source_node_id',
        'source_handle',
        'target_node_id',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'automation_version_id');
    }
}
