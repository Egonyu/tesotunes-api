<?php

namespace App\Settings;

use RuntimeException;

class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function register(SettingDefinition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw new RuntimeException("Setting [{$definition->key}] is already registered.");
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function require(string $key): SettingDefinition
    {
        return $this->get($key) ?? throw new RuntimeException("Setting [{$key}] is not registered.");
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<int, SettingDefinition>
     */
    public function forGroup(string $group): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (SettingDefinition $d) => $d->group === $group,
        ));
    }

    /**
     * @return array<int, SettingDefinition>
     */
    public function publicKeys(): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (SettingDefinition $d) => $d->isPublic(),
        ));
    }

    public function clear(): void
    {
        $this->definitions = [];
    }
}
