<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loyalty_cards')) {
            return;
        }

        // Only alter if the column type is an enum (not already a varchar/string)
        $columnType = Schema::getColumnType('loyalty_cards', 'status');
        if ($columnType === 'string') {
            return; // Already a string, nothing to do
        }

        // Change enum to string for more flexibility (add 'suspended', 'pending_review', etc.)
        DB::statement("ALTER TABLE loyalty_cards MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE loyalty_cards MODIFY COLUMN status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft'");
    }
};
