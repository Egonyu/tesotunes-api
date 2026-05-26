<?php

namespace App\Console\Commands;

use App\Models\Sacco\SaccoSettings;
use App\Models\Setting;
use App\Settings\Enums\SettingStore;
use App\Settings\SettingRegistry;
use Illuminate\Console\Command;

class SettingsDiffCommand extends Command
{
    protected $signature = 'settings:diff
        {--unregistered : Only show DB rows missing from the registry}
        {--deprecated   : Only show registered keys marked as deprecated}
        {--orphaned     : Only show registered keys with no DB row (defaults are used)}
        {--duplicates   : Only show duplicate logical names across groups}';

    protected $description = 'Report registry vs. DB drift, deprecated keys, and duplicate logical names.';

    public function handle(SettingRegistry $registry): int
    {
        $definitions = $registry->all();
        $registeredKeys = array_keys($definitions);

        $settingsTableKeys = Setting::query()->pluck('key')->all();
        $saccoTableKeys = SaccoSettings::query()->pluck('key')->map(fn ($k) => 'sacco_'.$k)->all();
        $dbKeys = array_unique(array_merge($settingsTableKeys, $saccoTableKeys));

        $unregistered = array_values(array_diff($settingsTableKeys, $registeredKeys));
        $orphaned = [];
        foreach ($definitions as $def) {
            $hasRow = match ($def->store) {
                SettingStore::SaccoTable => in_array(substr($def->key, strlen('sacco_')), array_column(SaccoSettings::query()->get(['key'])->toArray(), 'key'), true),
                default => in_array($def->key, $settingsTableKeys, true),
            };
            if (! $hasRow) {
                $orphaned[] = $def->key;
            }
        }
        $deprecated = array_filter($definitions, fn ($d) => $d->isDeprecated());

        $only = collect([
            'unregistered' => $this->option('unregistered'),
            'deprecated' => $this->option('deprecated'),
            'orphaned' => $this->option('orphaned'),
            'duplicates' => $this->option('duplicates'),
        ])->filter()->keys()->all();

        $showAll = empty($only);

        if ($showAll || in_array('unregistered', $only, true)) {
            $this->section('DB rows not in registry', $unregistered, 'key', fn ($k) => [$k]);
        }

        if ($showAll || in_array('orphaned', $only, true)) {
            $this->section('Registered keys with no DB row (defaults active)', $orphaned, 'key', fn ($k) => [$k]);
        }

        if ($showAll || in_array('deprecated', $only, true)) {
            $rows = [];
            foreach ($deprecated as $def) {
                $canonical = $def->deprecatedInFavorOf;
                $canonicalExists = $registry->has($canonical) ? 'yes' : 'MISSING';
                $rows[] = [$def->key, $canonical, $canonicalExists];
            }
            $this->titledTable(['deprecated key', 'canonical', 'canonical registered?'], $rows, 'Deprecated keys');
        }

        if ($showAll || in_array('duplicates', $only, true)) {
            $byLogical = [];
            foreach ($definitions as $def) {
                $logical = preg_replace('/^(general|users|security|appearance|payments|credits|email|storage|mobile|sacco|auth|notifications)_/', '', $def->key);
                $byLogical[$logical][] = $def->key;
            }
            $rows = [];
            foreach ($byLogical as $logical => $keys) {
                if (count($keys) > 1) {
                    $rows[] = [$logical, implode(', ', $keys), count($keys)];
                }
            }
            $this->titledTable(['logical name', 'keys', 'count'], $rows, 'Duplicate logical names');
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d registered | %d in DB | %d unregistered | %d orphaned | %d deprecated',
            count($registeredKeys),
            count($dbKeys),
            count($unregistered),
            count($orphaned),
            count($deprecated),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function section(string $title, array $items, string $header, \Closure $rowFn): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment> (".count($items).')');
        if ($items === []) {
            $this->line('  <fg=gray>none</>');

            return;
        }
        $this->table([$header], array_map($rowFn, $items));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int>>  $rows
     */
    private function titledTable(array $headers, array $rows, string $title): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment> (".count($rows).')');
        if ($rows === []) {
            $this->line('  <fg=gray>none</>');

            return;
        }
        $this->table($headers, $rows);
    }
}
