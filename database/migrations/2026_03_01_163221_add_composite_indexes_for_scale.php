<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes for common query patterns at scale.
     *
     * These cover the top 10 most-queried tables and their
     * hottest multi-column WHERE+ORDER BY patterns identified
     * in the performance audit.
     *
     * All indexes use hasIndex/hasColumn guards so the migration
     * is idempotent and safe to re-run.
     */
    public function up(): void
    {
        // ── songs ─────────────────────────────────────────────
        // CRITICAL: status + created_at (new releases, default listing)
        // CRITICAL: status + play_count (trending, popular)
        // HIGH: status + primary_genre_id + created_at (genre browsing)
        Schema::table('songs', function (Blueprint $table) {
            if (! $this->hasIndex('songs', 'songs_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'songs_status_created_at_index');
            }
            if (! $this->hasIndex('songs', 'songs_status_play_count_index')) {
                $table->index(['status', 'play_count'], 'songs_status_play_count_index');
            }
            if (! $this->hasIndex('songs', 'songs_status_genre_created_index')) {
                $table->index(['status', 'primary_genre_id', 'created_at'], 'songs_status_genre_created_index');
            }
        });

        // ── artists ───────────────────────────────────────────
        // HIGH: status + followers_count (popular artists listing)
        Schema::table('artists', function (Blueprint $table) {
            if (Schema::hasColumn('artists', 'followers_count') && ! $this->hasIndex('artists', 'artists_status_followers_index')) {
                $table->index(['status', 'followers_count'], 'artists_status_followers_index');
            }
        });

        // ── albums ────────────────────────────────────────────
        // HIGH: status + release_date (album listings)
        // MEDIUM: status + is_featured (featured albums)
        Schema::table('albums', function (Blueprint $table) {
            if (! $this->hasIndex('albums', 'albums_status_release_date_index')) {
                $table->index(['status', 'release_date'], 'albums_status_release_date_index');
            }
            if (Schema::hasColumn('albums', 'is_featured') && ! $this->hasIndex('albums', 'albums_status_featured_index')) {
                $table->index(['status', 'is_featured'], 'albums_status_featured_index');
            }
        });

        // ── payments ──────────────────────────────────────────
        // HIGH: status + created_at (admin payment listing)
        // HIGH: user_id + status + created_at (user payment history)
        Schema::table('payments', function (Blueprint $table) {
            if (! $this->hasIndex('payments', 'payments_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'payments_status_created_at_index');
            }
            if (! $this->hasIndex('payments', 'payments_user_status_created_index')) {
                $table->index(['user_id', 'status', 'created_at'], 'payments_user_status_created_index');
            }
        });

        // ── playlists ─────────────────────────────────────────
        // HIGH: is_public + follower_count (public playlist listing)
        Schema::table('playlists', function (Blueprint $table) {
            if (Schema::hasColumn('playlists', 'follower_count') && ! $this->hasIndex('playlists', 'playlists_public_followers_index')) {
                $table->index(['is_public', 'follower_count'], 'playlists_public_followers_index');
            }
            if (Schema::hasColumn('playlists', 'is_featured') && Schema::hasColumn('playlists', 'follower_count') && ! $this->hasIndex('playlists', 'playlists_featured_public_followers_index')) {
                $table->index(['is_featured', 'is_public', 'follower_count'], 'playlists_featured_public_followers_index');
            }
        });

        // ── podcasts ──────────────────────────────────────────
        // HIGH: status + created_at (podcast listing)
        // HIGH: podcast_category_id (category filter — no FK index)
        if (Schema::hasTable('podcasts')) {
            Schema::table('podcasts', function (Blueprint $table) {
                if (! $this->hasIndex('podcasts', 'podcasts_status_created_at_index')) {
                    $table->index(['status', 'created_at'], 'podcasts_status_created_at_index');
                }
                if (Schema::hasColumn('podcasts', 'podcast_category_id') && ! $this->hasIndex('podcasts', 'podcasts_category_id_index')) {
                    $table->index('podcast_category_id', 'podcasts_category_id_index');
                }
            });
        }

        // ── podcast_episodes ──────────────────────────────────
        // CRITICAL: podcast_id + status + created_at (episode listing per podcast)
        // HIGH: status + created_at (global episode listing)
        if (Schema::hasTable('podcast_episodes')) {
            Schema::table('podcast_episodes', function (Blueprint $table) {
                if (! $this->hasIndex('podcast_episodes', 'episodes_podcast_status_created_index')) {
                    $table->index(['podcast_id', 'status', 'created_at'], 'episodes_podcast_status_created_index');
                }
                if (! $this->hasIndex('podcast_episodes', 'episodes_status_created_at_index')) {
                    $table->index(['status', 'created_at'], 'episodes_status_created_at_index');
                }
            });
        }

        // ── campaigns ─────────────────────────────────────────
        // MEDIUM: status + created_at (campaign listing)
        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table) {
                if (! $this->hasIndex('campaigns', 'campaigns_status_created_at_index')) {
                    $table->index(['status', 'created_at'], 'campaigns_status_created_at_index');
                }
                if (! $this->hasIndex('campaigns', 'campaigns_status_featured_index')) {
                    $table->index(['status', 'is_featured'], 'campaigns_status_featured_index');
                }
            });
        }

        // ── campaign_pledges ──────────────────────────────────
        // MEDIUM: campaign_id (FK lookup — ensure index exists)
        if (Schema::hasTable('campaign_pledges')) {
            Schema::table('campaign_pledges', function (Blueprint $table) {
                if (! $this->hasIndex('campaign_pledges', 'pledges_campaign_id_index')) {
                    $table->index('campaign_id', 'pledges_campaign_id_index');
                }
            });
        }

        // ── events ────────────────────────────────────────────
        // MEDIUM: start_date + status (upcoming events)
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'start_date') && ! $this->hasIndex('events', 'events_start_date_status_index')) {
                    $table->index(['start_date', 'status'], 'events_start_date_status_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ── songs
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['songs_status_created_at_index']);
            $table->dropIndex(['songs_status_play_count_index']);
            $table->dropIndex(['songs_status_genre_created_index']);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['artists_status_followers_index']);
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['albums_status_release_date_index']);
            $table->dropIndex(['albums_status_featured_index']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payments_status_created_at_index']);
            $table->dropIndex(['payments_user_status_created_index']);
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropIndex(['playlists_public_followers_index']);
            $table->dropIndex(['playlists_featured_public_followers_index']);
        });

        if (Schema::hasTable('podcasts')) {
            Schema::table('podcasts', function (Blueprint $table) {
                $table->dropIndex(['podcasts_status_created_at_index']);
                $table->dropIndex(['podcasts_category_id_index']);
            });
        }

        if (Schema::hasTable('podcast_episodes')) {
            Schema::table('podcast_episodes', function (Blueprint $table) {
                $table->dropIndex(['episodes_podcast_status_created_index']);
                $table->dropIndex(['episodes_status_created_at_index']);
            });
        }

        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropIndex(['campaigns_status_created_at_index']);
                $table->dropIndex(['campaigns_status_featured_index']);
            });
        }

        if (Schema::hasTable('campaign_pledges')) {
            Schema::table('campaign_pledges', function (Blueprint $table) {
                $table->dropIndex(['pledges_campaign_id_index']);
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropIndex(['events_start_date_status_index']);
            });
        }
    }

    /**
     * Check if a named index exists on a table using raw MySQL query.
     * Avoids Schema::getIndexes() which can fail in some environments.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = \Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return count($indexes) > 0;
    }
};
