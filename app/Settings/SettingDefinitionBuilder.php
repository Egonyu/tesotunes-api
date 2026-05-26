<?php

namespace App\Settings;

use App\Settings\Enums\SettingScope;
use App\Settings\Enums\SettingStore;
use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;

final class SettingDefinitionBuilder
{
    private string $group = 'general';

    private ?string $subgroup = null;

    private SettingType $type = SettingType::String;

    private mixed $default = null;

    /** @var array<int, string> */
    private array $rules = [];

    private SettingScope $scope = SettingScope::Global;

    private SettingVisibility $visibility = SettingVisibility::Admin;

    /** @var array<int, string> */
    private array $editableBy = ['admin', 'super_admin'];

    private bool $requiresRestart = false;

    private bool $secret = false;

    private string $label = '';

    private ?string $help = null;

    private string $auditCategory = 'general';

    /** @var array<int, string|int>|null */
    private ?array $options = null;

    private ?SettingStore $store = null;

    private ?string $deprecatedInFavorOf = null;

    public function __construct(public readonly string $key) {}

    public function deprecatedInFavorOf(string $canonicalKey): self
    {
        $this->deprecatedInFavorOf = $canonicalKey;

        return $this;
    }

    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function subgroup(string $subgroup): self
    {
        $this->subgroup = $subgroup;

        return $this;
    }

    public function type(SettingType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * @param  array<int, string>  $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function scope(SettingScope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function visibility(SettingVisibility $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function editableBy(array $roles): self
    {
        $this->editableBy = $roles;

        return $this;
    }

    public function requiresRestart(bool $value = true): self
    {
        $this->requiresRestart = $value;

        return $this;
    }

    public function secret(bool $value = true): self
    {
        $this->secret = $value;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function auditCategory(string $category): self
    {
        $this->auditCategory = $category;

        return $this;
    }

    /**
     * @param  array<int, string|int>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function store(SettingStore $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function build(): SettingDefinition
    {
        $store = $this->store ?? match ($this->scope) {
            SettingScope::Env => SettingStore::Env,
            SettingScope::Secret => SettingStore::DbEncrypted,
            SettingScope::Global => SettingStore::Db,
        };

        $label = $this->label !== '' ? $this->label : $this->key;

        return new SettingDefinition(
            key: $this->key,
            group: $this->group,
            subgroup: $this->subgroup,
            type: $this->type,
            default: $this->default,
            rules: $this->rules,
            scope: $this->scope,
            visibility: $this->visibility,
            editableBy: $this->editableBy,
            requiresRestart: $this->requiresRestart,
            secret: $this->secret,
            label: $label,
            help: $this->help,
            auditCategory: $this->auditCategory,
            options: $this->options,
            store: $store,
            deprecatedInFavorOf: $this->deprecatedInFavorOf,
        );
    }

    public function register(): SettingDefinition
    {
        $definition = $this->build();
        app(SettingRegistry::class)->register($definition);

        return $definition;
    }
}
