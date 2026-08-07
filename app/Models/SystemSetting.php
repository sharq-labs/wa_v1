<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            "system_setting.{$key}",
            300,
            fn () => static::query()->where('key', $key)->value('value'),
        ) ?? $default;
    }

    public static function set(string $key, ?string $value, string $group = 'general'): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        Cache::forget("system_setting.{$key}");
    }
}
