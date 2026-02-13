<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SaccoSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
    ];

    protected $attributes = [
        'type' => 'string',
    ];

    // Static methods for easy access
    public static function getValue(string $key, $default = null)
    {
        return Cache::remember("sacco_setting:{$key}", 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    public static function setValue(string $key, $value, string $type = 'string'): void
    {
        self::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value) : $value,
                'type' => $type,
            ]
        );

        Cache::forget("sacco_setting:{$key}");
    }

    public static function getAll(): array
    {
        return Cache::remember('sacco_settings:all', 3600, function () {
            return self::all()->mapWithKeys(function ($setting) {
                return [$setting->key => self::castValue($setting->value, $setting->type)];
            })->toArray();
        });
    }

    protected static function castValue($value, string $type)
    {
        return match($type) {
            'integer' => (int) $value,
            'decimal', 'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    // Clear cache when settings change
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($setting) {
            Cache::forget("sacco_setting:{$setting->key}");
            Cache::forget('sacco_settings:all');
        });

        static::deleted(function ($setting) {
            Cache::forget("sacco_setting:{$setting->key}");
            Cache::forget('sacco_settings:all');
        });
    }
}
