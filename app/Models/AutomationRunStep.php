<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_run_id',
        'node_id',
        'node_type',
        'status',
        'input',
        'output',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
        ];
    }

    /**
     * Never persist credentials from HTTP/webhook node configuration in run
     * history. Definitions remain available only to automation managers; the
     * execution timeline stores a redacted diagnostic snapshot.
     */
    protected function input(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : json_decode($value, true),
            set: fn ($value) => $value === null ? null : json_encode($this->redactSecrets((array) $value)),
        );
    }

    protected function redactSecrets(array $value): array
    {
        $sensitive = [
            'authorization', 'token', 'access_token', 'api_key', 'apikey',
            'password', 'secret', 'client_secret', 'private_key',
        ];

        foreach ($value as $key => $item) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (in_array($normalized, $sensitive, true)) {
                $value[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($item)) {
                // Header rows are commonly stored as {key: Authorization, value: ...}.
                if (isset($item['key']) && strtolower((string) $item['key']) === 'authorization') {
                    $item['value'] = '[REDACTED]';
                }
                $value[$key] = $this->redactSecrets($item);
            }
        }

        return $value;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
