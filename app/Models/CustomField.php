<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    use BelongsToWorkspace, HasFactory;

    public const TYPES = [
        'text', 'textarea', 'number', 'date', 'datetime',
        'boolean', 'select', 'multi_select', 'email', 'phone',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'key',
        'type',
        'options',
        'default_value',
        'required',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ContactCustomFieldValue::class);
    }
}
