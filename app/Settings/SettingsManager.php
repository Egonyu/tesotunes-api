<?php

namespace App\Settings;

use App\Settings\Enums\SettingStore;
use App\Settings\Stores\DbStoreDriver;
use App\Settings\Stores\EncryptedDbStoreDriver;
use App\Settings\Stores\SaccoTableStoreDriver;
use App\Settings\Stores\SettingStoreDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SettingsManager
{
    /** @var array<string, SettingStoreDriver> */
    private array $drivers = [];

    /** @var array<string, mixed> */
    private array $requestCache = [];

    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly Container $container,
    ) {}

    public function registry(): SettingRegistry
    {
        return $this->registry;
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $definition = $this->registry->require($key);
        $value = $this->driverFor($definition)->read($definition);

        return $this->requestCache[$key] = $value;
    }

    public function set(string $key, mixed $value): void
    {
        $definition = $this->registry->require($key);

        if ($definition->rules !== []) {
            Validator::make(['value' => $value], ['value' => $definition->rules])->validate();
        }

        $this->driverFor($definition)->write($definition, $value);
        unset($this->requestCache[$key]);
    }

    public function forget(string $key): void
    {
        $definition = $this->registry->require($key);
        $this->driverFor($definition)->forget($definition);
        unset($this->requestCache[$key]);
    }

    public function flushRequestCache(): void
    {
        $this->requestCache = [];
    }

    private function driverFor(SettingDefinition $definition): SettingStoreDriver
    {
        $store = $definition->store;
        $cacheKey = $store->value;

        if (isset($this->drivers[$cacheKey])) {
            return $this->drivers[$cacheKey];
        }

        return $this->drivers[$cacheKey] = match ($store) {
            SettingStore::Db => $this->container->make(DbStoreDriver::class),
            SettingStore::DbEncrypted => $this->container->make(EncryptedDbStoreDriver::class),
            SettingStore::SaccoTable => $this->container->make(SaccoTableStoreDriver::class),
            SettingStore::Env => throw new RuntimeException(
                "Setting store [{$store->value}] driver is not yet wired."
            ),
        };
    }
}
