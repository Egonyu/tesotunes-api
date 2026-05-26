<?php

namespace App\Settings\Stores;

use App\Settings\SettingDefinition;

interface SettingStoreDriver
{
    public function read(SettingDefinition $definition): mixed;

    public function write(SettingDefinition $definition, mixed $value): void;

    public function forget(SettingDefinition $definition): void;
}
