<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing FK constraint for publishing_rights.owner_id.
     *
     * Note: events.event_location_id, podcasts.podcast_category_id, and
     * song_moods.mood_id already have FK constraints from earlier migrations.
     * Only publishing_rights.owner_id was missing.
     *
     * Addresses DATABASE_HEALTH_REPORT item #9 (HIGH).
     */
    public function up(): void
    {
        // Clean up orphaned owner_id references before adding FK constraint
        $this->cleanOrphanedRows('publishing_rights', 'owner_id', 'users');

        // publishing_rights.owner_id → users.id (SET NULL on delete — preserve rights record)
        if (Schema::hasTable('publishing_rights') && Schema::hasColumn('publishing_rights', 'owner_id') && Schema::hasTable('users')) {
            // Make owner_id nullable first using raw SQL to avoid any Doctrine dependency
            DB::statement('ALTER TABLE `publishing_rights` MODIFY `owner_id` BIGINT UNSIGNED NULL');

            Schema::table('publishing_rights', function (Blueprint $table) {
                $table->foreign('owner_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('publishing_rights')) {
            Schema::table('publishing_rights', function (Blueprint $table) {
                $table->dropForeign('publishing_rights_owner_id_foreign');
            });
        }
    }

    /**
     * Null out orphaned foreign key references before adding constraint.
     */
    private function cleanOrphanedRows(string $table, string $column, string $parentTable): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($parentTable)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table($parentTable)->select('id'))
            ->update([$column => null]);
    }
};
