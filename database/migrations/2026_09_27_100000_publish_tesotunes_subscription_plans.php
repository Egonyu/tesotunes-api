<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        // Publish the canonical launch plans once. Prices, limits and all other
        // fields remain untouched so Admin stays the source of truth.
        DB::table('subscription_plans')
            ->whereIn('slug', ['free', 'emong', 'eris', 'engatuny'])
            ->update([
                'is_active' => true,
                'is_visible' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Visibility may have been changed deliberately in Admin after this
        // migration. Rolling back must not overwrite that later decision.
    }
};
