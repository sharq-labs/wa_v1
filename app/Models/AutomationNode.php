<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_version_id',
        'node_id',
        'type',
        'config',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'array',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'automation_version_id');
    }
}
