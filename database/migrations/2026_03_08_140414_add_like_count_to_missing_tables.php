<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = ['artists', 'playlists', 'events', 'activities'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'like_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedInteger('like_count')->default(0)->after('id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['artists', 'playlists', 'events', 'activities'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'like_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('like_count');
                });
            }
        }
    }
};
