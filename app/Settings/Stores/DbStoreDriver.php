<?php

namespace App\Settings\Stores;

use App\Models\Setting;
use App\Settings\SettingDefinition;
use Illuminate\Support\Facades\Cache;

class DbStoreDriver implements SettingStoreDriver
{
    private const CACHE_TTL_SECONDS = 600;

    private const SENTINEL_MISS = '__setting_miss__';

    public function read(SettingDefinition $definition): mixed
    {
        $cached = Cache::get($this->cacheKey($definition->key));

        if ($cached === self::SENTINEL_MISS) {
            return $definition->default;
        }

        if ($cached !== null) {
            return $definition->type->cast($cached);
        }

        $row = Setting::query()->where('key', $definition->key)->first();

        if ($row === null) {
            Cache::put($this->cacheKey($definition->key), self::SENTINEL_MISS, self::CACHE_TTL_SECONDS);

            return $definition->default;
        }

        Cache::put($this->cacheKey($definition->key), $row->value, self::CACHE_TTL_SECONDS);

        return $definition->type->cast($row->value);
    }

    public function write(SettingDefinition $definition, mixed $value): void
    {
        $row = Setting::query()->firstOrNew(['key' => $definition->key]);
        $row->fill([
            'value' => $definition->type->serialize($value),
            'type' => $definition->type->value,
            'group' => $definition->group,
            'is_public' => $definition->isPublic(),
            'is_secret' => $definition->secret,
        ]);
        $row->save();

        Cache::forget($this->cacheKey($definition->key));
    }

    public function forget(SettingDefinition $definition): void
    {
        Setting::query()->where('key', $definition->key)->delete();
        Cache::forget($this->cacheKey($definition->key));
    }

    private function cacheKey(string $key): string
    {
        return "setting:db:{$key}";
    }
}
