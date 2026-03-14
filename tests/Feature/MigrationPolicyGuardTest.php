<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrationPolicyGuardTest extends TestCase
{
    private const ALLOWED_NON_BASELINE_MIGRATIONS = [
        '2026_02_23_090003_create_telescope_entries_table.php',
    ];

    private const BANNED_NAME_FRAGMENTS = [
        'fix_',
        'add_missing_',
        'create_missing_',
        'ensure_',
        '_sync',
        'comprehensive_schema_sync',
    ];

    #[Test]
    public function migration_filenames_follow_the_baseline_policy(): void
    {
        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->values();

        foreach ($files as $file) {
            $isBaseline = str_starts_with($file, '0001_01_01_');
            $isAllowedException = in_array($file, self::ALLOWED_NON_BASELINE_MIGRATIONS, true);

            $this->assertTrue(
                $isBaseline || $isAllowedException,
                "Migration [{$file}] is outside the approved baseline/exception policy."
            );

            foreach (self::BANNED_NAME_FRAGMENTS as $fragment) {
                $this->assertStringNotContainsString(
                    $fragment,
                    $file,
                    "Migration [{$file}] reintroduces banned patch-style naming."
                );
            }
        }
    }

    #[Test]
    public function baseline_migrations_do_not_use_defensive_schema_repairs(): void
    {
        $baselineFiles = collect(glob(database_path('migrations/0001_01_01_*.php')));
        $bannedSnippets = [
            'Schema::hasTable(',
            'Schema::hasColumn(',
            'Schema::rename(',
        ];

        foreach ($baselineFiles as $file) {
            $contents = file_get_contents($file);
            $name = basename($file);

            foreach ($bannedSnippets as $snippet) {
                $this->assertStringNotContainsString(
                    $snippet,
                    $contents,
                    "Baseline migration [{$name}] contains repair-style schema logic: {$snippet}"
                );
            }
        }
    }
}
