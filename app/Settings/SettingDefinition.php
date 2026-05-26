<?php

namespace App\Settings;

use App\Settings\Enums\SettingScope;
use App\Settings\Enums\SettingStore;
use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;

final class SettingDefinition
{
    /**
     * @param  array<int, string>  $rules
     * @param  array<int, string>  $editableBy
     * @param  array<int, string|int>|null  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly ?string $subgroup,
        public readonly SettingType $type,
        public readonly mixed $default,
        public readonly array $rules,
        public readonly SettingScope $scope,
        public readonly SettingVisibility $visibility,
        public readonly array $editableBy,
        public readonly bool $requiresRestart,
        public readonly bool $secret,
        public readonly string $label,
        public readonly ?string $help,
        public readonly string $auditCategory,
        public readonly ?array $options,
        public readonly SettingStore $store,
        public readonly ?string $deprecatedInFavorOf = null,
    ) {}

    public function isDeprecated(): bool
    {
        return $this->deprecatedInFavorOf !== null;
    }

    public static function make(string $key): SettingDefinitionBuilder
    {
        return new SettingDefinitionBuilder($key);
    }

    public function isPublic(): bool
    {
        return $this->visibility === SettingVisibility::Public;
    }

    public function requiresSuperAdmin(): bool
    {
        return $this->visibility === SettingVisibility::SuperAdmin
            || $this->store === SettingStore::Env
            || in_array('super_admin', $this->editableBy, true) && ! in_array('admin', $this->editableBy, true);
    }
}
