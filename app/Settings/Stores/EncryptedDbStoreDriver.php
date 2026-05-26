<?php

namespace App\Settings\Stores;

use App\Models\Setting;
use App\Settings\SettingDefinition;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class EncryptedDbStoreDriver implements SettingStoreDriver
{
    public function read(SettingDefinition $definition): ?string
    {
        $row = Setting::query()->where('key', $definition->key)->first();

        if ($row === null || $row->value === null || $row->value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($row->value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function write(SettingDefinition $definition, mixed $value): void
    {
        $string = $value === null ? null : Crypt::encryptString((string) $value);

        $row = Setting::query()->firstOrNew(['key' => $definition->key]);
        $row->fill([
            'value' => $string,
            'type' => $definition->type->value,
            'group' => $definition->group,
            'is_public' => false,
            'is_secret' => true,
        ]);
        $row->save();
    }

    public function forget(SettingDefinition $definition): void
    {
        Setting::query()->where('key', $definition->key)->delete();
    }
}
