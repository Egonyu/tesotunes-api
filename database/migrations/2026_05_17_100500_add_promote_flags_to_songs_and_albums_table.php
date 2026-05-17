<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Artist opt-in: shows the "Promote this track" button on the song page
            $table->boolean('promotions_enabled')->default(true)->after('comments_count');

            // Denormalized counters — updated by OpportunityObserver
            $table->unsignedInteger('active_opportunity_count')->default(0)->after('promotions_enabled');
            $table->unsignedInteger('total_promotions_count')->default(0)->after('active_opportunity_count');

            $table->index(
                ['status', 'promotions_enabled'],
                'songs_promote_idx'
            );
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('promotions_enabled')->default(true)->after('comments_count');
            $table->unsignedInteger('active_opportunity_count')->default(0)->after('promotions_enabled');
            $table->unsignedInteger('total_promotions_count')->default(0)->after('active_opportunity_count');

            $table->index(
                ['status', 'promotions_enabled'],
                'albums_promote_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex('songs_promote_idx');
            $table->dropColumn(['promotions_enabled', 'active_opportunity_count', 'total_promotions_count']);
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex('albums_promote_idx');
            $table->dropColumn(['promotions_enabled', 'active_opportunity_count', 'total_promotions_count']);
        });
    }
};
