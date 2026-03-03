<?php

use Illuminate\Database\Migrations\Migration;

/**
 * DEPRECATED: This migration is a duplicate of 2026_02_15_191346_fix_award_nominations_columns.
 * Both add the same columns with hasColumn() guards. Converted to no-op to reduce clutter.
 * Cannot be deleted because it's recorded in the migrations table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op — duplicate of 2026_02_15_191346_fix_award_nominations_columns
    }

    public function down(): void
    {
        // No-op
    }
};
