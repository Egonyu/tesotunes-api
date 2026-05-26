<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: storage — driver and allowed formats.
 * Driver is decorative until Env driver wires it back to .env; flagged requiresRestart.
 */
final class StorageSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'storage';
        $cat = 'storage';

        Define::enum('storage_driver', ['local', 's3', 'gcs', 'do_spaces'], 's3')
            ->group($g)->subgroup('storage')
            ->requiresRestart()
            ->label('Storage driver')->auditCategory($cat)->register();

        Define::int('storage_max_upload_mb', 100)
            ->group($g)->subgroup('storage')
            ->rules(['integer', 'min:1', 'max:5120'])
            ->label('Max upload size (MB)')->auditCategory($cat)->register();

        Define::str('storage_allowed_audio_formats', 'mp3,wav,flac,aac')
            ->group($g)->subgroup('storage')
            ->label('Allowed audio formats')->auditCategory($cat)->register();
        Define::str('storage_allowed_image_formats', 'jpg,jpeg,png,webp')
            ->group($g)->subgroup('storage')
            ->label('Allowed image formats')->auditCategory($cat)->register();
    }
}
