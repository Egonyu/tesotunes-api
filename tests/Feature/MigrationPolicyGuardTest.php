<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrationPolicyGuardTest extends TestCase
{
    private const ALLOWED_NON_BASELINE_MIGRATIONS = [
        '2026_02_23_090003_create_telescope_entries_table.php',
        '2026_03_19_120000_add_admin_genre_fields.php',
        '2026_03_16_120000_create_featured_content_table.php',
        '2026_03_19_180000_add_catalog_claim_fields_to_artists_and_songs.php',
        '2026_03_19_181000_create_catalog_intake_tables.php',
        '2026_03_20_000100_create_moderation_reports_table.php',
        '2026_03_21_160000_add_ticketing_mode_to_events_table.php',
        '2026_03_21_190000_create_event_payout_ledger_entries_table.php',
        '2026_03_21_220000_create_event_staff_members_table.php',
        '2026_03_21_230000_add_marketing_settings_to_events_table.php',
        '2026_03_21_233000_create_event_waitlist_entries_table.php',
        '2026_03_21_234500_create_event_discount_codes_table.php',
        '2026_03_26_120000_create_event_funnel_touchpoints_table.php',
        '2026_03_26_133000_create_event_ticket_cases_table.php',
        '2026_03_26_150000_create_event_ticket_channel_allocations_table.php',
        '2026_03_26_160000_create_event_promotion_requests_table.php',
        '2026_03_26_170000_add_dispute_fields_to_event_ticket_cases_table.php',
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
