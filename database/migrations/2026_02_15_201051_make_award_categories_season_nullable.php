<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('award_categories') || ! Schema::hasColumn('award_categories', 'award_season_id')) {
            return;
        }

        // award_season_id is already nullable in the awards table migration
        // This migration only needed if it was NOT NULL
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
