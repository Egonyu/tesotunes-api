<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * HIGH-priority fixes: Add missing indexes and SoftDeletes columns
     */
    public function up(): void
    {
        // Add missing indexes on songs table for performance
        Schema::table('songs', function (Blueprint $table) {
            if (! $this->hasIndex('songs', 'songs_primary_genre_id_index')) {
                $table->index('primary_genre_id', 'songs_primary_genre_id_index');
            }
            if (Schema::hasColumn('songs', 'release_date') && ! $this->hasIndex('songs', 'songs_release_date_index')) {
                $table->index('release_date', 'songs_release_date_index');
            }
            if (Schema::hasColumn('songs', 'play_count') && ! $this->hasIndex('songs', 'songs_play_count_index')) {
                $table->index('play_count', 'songs_play_count_index');
            }
        });

        // Add missing indexes on albums table
        Schema::table('albums', function (Blueprint $table) {
            if (Schema::hasColumn('albums', 'primary_genre_id') && ! $this->hasIndex('albums', 'albums_primary_genre_id_index')) {
                $table->index('primary_genre_id', 'albums_primary_genre_id_index');
            }
        });

        // Add missing indexes on artists table
        Schema::table('artists', function (Blueprint $table) {
            if (Schema::hasColumn('artists', 'primary_genre_id') && ! $this->hasIndex('artists', 'artists_primary_genre_id_index')) {
                $table->index('primary_genre_id', 'artists_primary_genre_id_index');
            }
        });

        // Add missing deleted_at columns for SoftDeletes trait consistency
        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'deleted_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('feed_items') && ! Schema::hasColumn('feed_items', 'deleted_at')) {
            Schema::table('feed_items', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('campaign_updates') && ! Schema::hasColumn('campaign_updates', 'deleted_at')) {
            Schema::table('campaign_updates', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('sacco_members') && ! Schema::hasColumn('sacco_members', 'deleted_at')) {
            Schema::table('sacco_members', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Add missing indexes on payments table
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'provider_transaction_id') && ! $this->hasIndex('payments', 'payments_provider_transaction_id_index')) {
                $table->index('provider_transaction_id', 'payments_provider_transaction_id_index');
            }
            if (Schema::hasColumn('payments', 'transaction_reference') && ! $this->hasIndex('payments', 'payments_transaction_reference_index')) {
                $table->index('transaction_reference', 'payments_transaction_reference_index');
            }
            if (! $this->hasIndex('payments', 'payments_created_at_index')) {
                $table->index('created_at', 'payments_created_at_index');
            }
        });

        // Add missing indexes on users table
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referrer_id') && ! $this->hasIndex('users', 'users_referrer_id_index')) {
                $table->index('referrer_id', 'users_referrer_id_index');
            }
            if (Schema::hasColumn('users', 'last_login_at') && ! $this->hasIndex('users', 'users_last_login_at_index')) {
                $table->index('last_login_at', 'users_last_login_at_index');
            }
        });

        // Add missing index on user_subscriptions
        if (Schema::hasTable('user_subscriptions')) {
            Schema::table('user_subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('user_subscriptions', 'ends_at') && ! $this->hasIndex('user_subscriptions', 'user_subscriptions_ends_at_index')) {
                    $table->index('ends_at', 'user_subscriptions_ends_at_index');
                }
            });
        }
    }

    /**
     * Check if an index exists on a table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $indexes = $connection->getDoctrineSchemaManager()->listTableIndexes($table);

        return isset($indexes[$indexName]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from songs table
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex('songs_primary_genre_id_index');
            $table->dropIndex('songs_release_date_index');
            $table->dropIndex('songs_play_count_index');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex('albums_primary_genre_id_index');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex('artists_primary_genre_id_index');
        });

        // Remove softDeletes columns
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('campaign_updates', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('sacco_members', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        // Remove payment indexes
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_provider_transaction_id_index');
            $table->dropIndex('payments_transaction_reference_index');
            $table->dropIndex('payments_created_at_index');
        });

        // Remove user indexes
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_referrer_id_index');
            $table->dropIndex('users_last_login_at_index');
        });

        if (Schema::hasTable('user_subscriptions')) {
            Schema::table('user_subscriptions', function (Blueprint $table) {
                $table->dropIndex('user_subscriptions_ends_at_index');
            });
        }
    }
};
