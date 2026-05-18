<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'songs_status_created_at_index');
            $table->index(['status', 'play_count'], 'songs_status_play_count_index');
            $table->index(['status', 'primary_genre_id', 'created_at'], 'songs_status_genre_created_index');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->index(['status', 'followers_count'], 'artists_status_followers_index');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->index(['status', 'release_date'], 'albums_status_release_date_index');
            $table->index(['status', 'is_featured'], 'albums_status_featured_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'payments_status_created_at_index');
            $table->index(['user_id', 'status', 'created_at'], 'payments_user_status_created_index');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->index(['is_public', 'followers_count'], 'playlists_public_followers_index');
            $table->index(['is_featured', 'is_public', 'followers_count'], 'playlists_featured_public_followers_index');
        });

        Schema::table('podcasts', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'podcasts_status_created_at_index');
            $table->index('podcast_category_id', 'podcasts_category_id_index');
        });

        Schema::table('podcast_episodes', function (Blueprint $table) {
            $table->index(['podcast_id', 'status', 'created_at'], 'episodes_podcast_status_created_index');
            $table->index(['status', 'created_at'], 'episodes_status_created_at_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'campaigns_status_created_at_index');
            $table->index(['status', 'is_featured'], 'campaigns_status_featured_index');
        });

        Schema::table('campaign_pledges', function (Blueprint $table) {
            $table->index('campaign_id', 'pledges_campaign_id_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index(['starts_at', 'status'], 'events_starts_at_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_starts_at_status_index');
        });

        Schema::table('campaign_pledges', function (Blueprint $table) {
            $table->dropIndex('pledges_campaign_id_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_status_created_at_index');
            $table->dropIndex('campaigns_status_featured_index');
        });

        Schema::table('podcast_episodes', function (Blueprint $table) {
            $table->dropIndex('episodes_podcast_status_created_index');
            $table->dropIndex('episodes_status_created_at_index');
        });

        Schema::table('podcasts', function (Blueprint $table) {
            $table->dropIndex('podcasts_status_created_at_index');
            $table->dropIndex('podcasts_category_id_index');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropIndex('playlists_public_followers_index');
            $table->dropIndex('playlists_featured_public_followers_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_created_at_index');
            $table->dropIndex('payments_user_status_created_index');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex('albums_status_release_date_index');
            $table->dropIndex('albums_status_featured_index');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex('artists_status_followers_index');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex('songs_status_created_at_index');
            $table->dropIndex('songs_status_play_count_index');
            $table->dropIndex('songs_status_genre_created_index');
        });
    }
};
