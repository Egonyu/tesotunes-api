<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'artists',
        'songs',
        'albums',
        'playlists',
        'events',
        'activities',
        'podcasts',
        'podcast_episodes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'comments_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedInteger('comments_count')->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'comments_count')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('comments_count');
                });
            }
        }
    }
};
