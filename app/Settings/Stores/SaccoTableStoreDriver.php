<?php

namespace App\Settings\Stores;

use App\Models\Sacco\SaccoSettings;
use App\Models\SettingAudit;
use App\Settings\Enums\SettingType;
use App\Settings\SettingActor;
use App\Settings\SettingDefinition;

/**
 * Reads and writes SACCO-domain settings through the legacy sacco_settings
 * table (and its SaccoSettings model). Bridges the registry without forcing
 * the existing SACCO consumers to migrate.
 *
 * Registry keys carry a "sacco_" prefix (e.g. "sacco_share_price_ugx"); the
 * underlying table uses the bare logical key ("share_price_ugx"). The
 * driver translates between them.
 *
 * Writes go through SaccoSettings::setValue so the model's own cache hooks
 * still fire. The driver layers audit-row creation on top, matching what
 * the Setting model boot hooks do for the main settings table.
 */
class SaccoTableStoreDriver implements SettingStoreDriver
{
    public function read(SettingDefinition $definition): mixed
    {
        $raw = SaccoSettings::getValue($this->tableKey($definition), null);

        return $raw === null ? $definition->default : $definition->type->cast($raw);
    }

    public function write(SettingDefinition $definition, mixed $value): void
    {
        $tableKey = $this->tableKey($definition);

        $oldRaw = optional(SaccoSettings::query()->where('key', $tableKey)->first())->value;
        $oldVersion = $this->versionForKey($definition->key);

        SaccoSettings::setValue($tableKey, $value, $this->saccoTypeFor($definition->type));

        $newRaw = optional(SaccoSettings::query()->where('key', $tableKey)->first())->value;
        $newVersion = $oldVersion + 1;

        SettingAudit::query()->create([
            'setting_key' => $definition->key,
            'group' => $definition->group,
            'audit_category' => $definition->auditCategory,
            'old_value' => $definition->secret ? null : $oldRaw,
            'new_value' => $definition->secret ? null : $newRaw,
            'old_version' => $oldRaw === null ? null : $oldVersion,
            'new_version' => $newVersion,
            'actor_user_id' => SettingActor::currentActorId(),
            'actor_ip' => SettingActor::currentIp(),
            'actor_role' => SettingActor::currentRole(),
            'reason' => SettingActor::currentReason(),
            'was_secret' => $definition->secret,
            'changed_at' => now(),
        ]);
    }

    public function forget(SettingDefinition $definition): void
    {
        SaccoSettings::query()->where('key', $this->tableKey($definition))->delete();
    }

    private function tableKey(SettingDefinition $definition): string
    {
        return str_starts_with($definition->key, 'sacco_')
            ? substr($definition->key, strlen('sacco_'))
            : $definition->key;
    }

    /**
     * Newest audit row for this key drives the next version number.
     * Falls back to 0 so the first write becomes version 1.
     */
    private function versionForKey(string $key): int
    {
        return (int) (SettingAudit::query()
            ->where('setting_key', $key)
            ->orderByDesc('id')
            ->value('new_version') ?? 0);
    }

    private function saccoTypeFor(SettingType $type): string
    {
        return match ($type) {
            SettingType::Boolean => 'boolean',
            SettingType::Integer => 'integer',
            SettingType::Float => 'float',
            SettingType::Json => 'json',
            default => 'string',
        };
    }
}
