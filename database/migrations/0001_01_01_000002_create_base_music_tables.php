<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==========================================
        // ROLES
        // ==========================================
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->json('permissions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('priority')->default(0);
                $table->timestamps();
            });
        }

        // ==========================================
        // PERMISSIONS
        // ==========================================
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('slug', 100)->unique();
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->string('group')->nullable();
                $table->timestamps();
            });
        }

        // ==========================================
        // ROLE_PERMISSIONS (Pivot)
        // ==========================================
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'permission_id']);
            });
        }

        // ==========================================
        // USER_ROLES (Pivot)
        // ==========================================
        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'role_id']);
            });
        }

        // ==========================================
        // GENRES
        // ==========================================
        if (!Schema::hasTable('genres')) {
            Schema::create('genres', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->text('description')->nullable();
                $table->string('artwork')->nullable();
                $table->string('banner')->nullable();
                $table->string('color', 7)->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('genres')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'is_featured']);
            });
        }

        // ==========================================
        // ARTISTS
        // ==========================================
        if (!Schema::hasTable('artists')) {
            Schema::create('artists', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 200);
                $table->string('stage_name', 200)->nullable();
                $table->string('slug', 220)->unique();
                $table->text('bio')->nullable();
                $table->string('avatar')->nullable();
                $table->string('banner')->nullable();
                $table->string('country', 100)->nullable()->default('Uganda');
                $table->string('city', 100)->nullable();
                $table->string('website_url')->nullable();
                $table->json('social_links')->nullable();
                $table->foreignId('primary_genre_id')->nullable()->constrained('genres')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->boolean('is_verified')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->boolean('can_upload')->default(true);
                $table->boolean('auto_publish')->default(false);
                $table->integer('monthly_upload_limit')->default(10);
                $table->integer('followers_count')->default(0);
                $table->integer('total_plays')->default(0);
                $table->integer('career_start_year')->nullable();
                $table->string('record_label')->nullable();
                $table->text('influences')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'is_verified']);
                $table->index(['is_featured', 'total_plays']);
            });
        }

        // ==========================================
        // ALBUMS
        // ==========================================
        if (!Schema::hasTable('albums')) {
            Schema::create('albums', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
                $table->string('title', 255);
                $table->string('slug', 280)->unique();
                $table->text('description')->nullable();
                $table->string('artwork')->nullable();
                $table->string('album_type')->default('album');
                $table->foreignId('primary_genre_id')->nullable()->constrained('genres')->nullOnDelete();
                $table->date('release_date')->nullable();
                $table->string('status')->default('draft');
                $table->boolean('is_explicit')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->decimal('price', 10, 2)->nullable();
                $table->string('currency', 10)->default('UGX');
                $table->integer('play_count')->default(0);
                $table->integer('like_count')->default(0);
                $table->integer('download_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['artist_id', 'status']);
                $table->index(['release_date']);
            });
        }

        // ==========================================
        // SONGS
        // ==========================================
        if (!Schema::hasTable('songs')) {
            Schema::create('songs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title', 255);
                $table->string('slug', 280)->unique();
                $table->text('description')->nullable();
                $table->text('lyrics')->nullable();
                $table->string('artwork')->nullable();
                $table->string('audio_file_original')->nullable();
                $table->string('audio_file_320')->nullable();
                $table->string('audio_file_128')->nullable();
                $table->integer('duration_seconds')->default(0);
                $table->foreignId('primary_genre_id')->nullable()->constrained('genres')->nullOnDelete();
                $table->date('release_date')->nullable();
                $table->string('status')->default('draft');
                $table->boolean('is_explicit')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_free')->default(true);
                $table->boolean('is_downloadable')->default(true);
                $table->decimal('price', 10, 2)->nullable();
                $table->string('currency', 10)->default('UGX');
                $table->json('featured_artists')->nullable();
                $table->string('composer')->nullable();
                $table->string('producer')->nullable();
                $table->integer('play_count')->default(0);
                $table->integer('like_count')->default(0);
                $table->integer('download_count')->default(0);
                $table->integer('track_number')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['artist_id', 'status']);
                $table->index(['album_id', 'track_number']);
                $table->index(['is_featured', 'play_count']);
            });
        }

        // ==========================================
        // LIKES (Polymorphic)
        // ==========================================
        if (!Schema::hasTable('likes')) {
            Schema::create('likes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->morphs('likeable');
                $table->string('type', 20)->default('like');
                $table->timestamps();

                $table->unique(['user_id', 'likeable_type', 'likeable_id', 'type'], 'likes_unique');
            });
        }

        // ==========================================
        // USER FOLLOWS
        // ==========================================
        if (!Schema::hasTable('user_follows')) {
            Schema::create('user_follows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['follower_id', 'following_id']);
                $table->index('following_id');
            });
        }

        // ==========================================
        // PLAY_HISTORY
        // ==========================================
        if (!Schema::hasTable('play_history')) {
            Schema::create('play_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('song_id')->constrained()->cascadeOnDelete();
                $table->integer('duration_played')->default(0);
                $table->boolean('completed')->default(false);
                $table->string('source')->nullable();
                $table->string('device_type')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['song_id', 'created_at']);
            });
        }

        // ==========================================
        // DOWNLOADS
        // ==========================================
        if (!Schema::hasTable('downloads')) {
            Schema::create('downloads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->morphs('downloadable');
                $table->string('quality')->default('320');
                $table->string('source')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }

        // ==========================================
        // PLAYLISTS
        // ==========================================
        if (!Schema::hasTable('playlists')) {
            Schema::create('playlists', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 200);
                $table->string('slug', 220)->unique();
                $table->text('description')->nullable();
                $table->string('artwork')->nullable();
                $table->boolean('is_public')->default(true);
                $table->boolean('is_collaborative')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->string('status')->default('active');
                $table->integer('followers_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'is_public']);
            });
        }

        // Playlist songs pivot
        if (!Schema::hasTable('playlist_song')) {
            Schema::create('playlist_song', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('song_id')->constrained()->cascadeOnDelete();
                $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('position')->default(0);
                $table->timestamps();

                $table->unique(['playlist_id', 'song_id']);
            });
        }

        // ==========================================
        // EVENTS
        // ==========================================
        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('organizer_type')->default('user');
                $table->foreignId('event_location_id')->nullable();
                $table->string('title', 200);
                $table->string('slug', 220)->unique();
                $table->text('description')->nullable();
                $table->boolean('is_virtual')->default(false);
                $table->string('virtual_link')->nullable();
                $table->string('cover_image')->nullable();
                $table->string('artwork')->nullable();
                $table->string('banner')->nullable();
                $table->string('featured_image')->nullable();
                $table->json('gallery')->nullable();
                $table->string('event_type')->nullable();
                $table->string('category')->nullable();
                $table->string('venue_name')->nullable();
                $table->string('venue_address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable()->default('Uganda');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->datetime('starts_at');
                $table->datetime('ends_at')->nullable();
                $table->datetime('doors_open_at')->nullable();
                $table->datetime('registration_deadline')->nullable();
                $table->string('timezone')->default('Africa/Kampala');
                $table->string('status')->default('draft');
                $table->string('visibility')->default('public');
                $table->boolean('requires_approval')->default(false);
                $table->boolean('is_published')->default(false);
                $table->datetime('published_at')->nullable();
                $table->integer('total_tickets')->nullable();
                $table->integer('capacity')->nullable();
                $table->integer('attendee_limit')->nullable();
                $table->integer('tickets_sold')->default(0);
                $table->integer('attendee_count')->default(0);
                $table->boolean('is_free')->default(true);
                $table->decimal('ticket_price', 12, 2)->nullable();
                $table->string('currency', 10)->default('UGX');
                $table->boolean('is_featured')->default(false);
                $table->string('required_loyalty_tier')->nullable();
                $table->foreignId('loyalty_card_id')->nullable();
                $table->integer('tier_early_access_hours')->nullable();
                $table->boolean('hide_from_non_qualifying')->default(false);
                $table->text('cancellation_policy')->nullable();
                $table->text('refund_policy')->nullable();
                $table->text('requirements')->nullable();
                $table->json('contact_info')->nullable();
                $table->string('website')->nullable();
                $table->json('social_links')->nullable();
                $table->json('tags')->nullable();
                $table->decimal('rating_average', 3, 2)->nullable();
                $table->integer('review_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'starts_at']);
                $table->index(['is_featured', 'starts_at']);
            });
        }

        // ==========================================
        // EVENT LOCATIONS
        // ==========================================
        if (!Schema::hasTable('event_locations')) {
            Schema::create('event_locations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 200);
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable()->default('Uganda');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->integer('capacity')->nullable();
                $table->text('description')->nullable();
                $table->json('amenities')->nullable();
                $table->boolean('is_verified')->default(false);
                $table->timestamps();
            });
        }

        // ==========================================
        // EVENT TICKETS
        // ==========================================
        if (!Schema::hasTable('event_tickets')) {
            Schema::create('event_tickets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('price_ugx', 12, 2)->default(0);
                $table->integer('price_credits')->default(0);
                $table->boolean('is_free')->default(false);
                $table->integer('quantity_total')->default(0);
                $table->integer('quantity_sold')->default(0);
                $table->integer('min_per_order')->default(1);
                $table->integer('max_per_order')->default(10);
                $table->datetime('sale_starts_at')->nullable();
                $table->datetime('sale_ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['event_id', 'is_active']);
            });
        }

        // ==========================================
        // EVENT ATTENDEES
        // ==========================================
        if (!Schema::hasTable('event_attendees')) {
            Schema::create('event_attendees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ticket_id')->nullable()->constrained('event_tickets')->nullOnDelete();
                $table->string('status')->default('registered');
                $table->datetime('checked_in_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['event_id', 'user_id']);
            });
        }

        // ==========================================
        // NOTIFICATIONS
        // ==========================================
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        // ==========================================
        // PAYMENTS & TRANSACTIONS
        // ==========================================
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('payment_type');
                $table->morphs('payable');
                $table->decimal('amount', 15, 2);
                $table->string('currency', 10)->default('UGX');
                $table->string('payment_method')->nullable();
                $table->string('provider')->nullable();
                $table->string('provider_transaction_id')->nullable();
                $table->string('status')->default('pending');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        // ==========================================
        // USER CREDITS
        // ==========================================
        if (!Schema::hasTable('user_credits')) {
            Schema::create('user_credits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->decimal('balance', 15, 2)->default(0);
                $table->string('currency', 10)->default('credits');
                $table->timestamps();

                $table->unique('user_id');
            });
        }

        // ==========================================
        // CREDIT TRANSACTIONS
        // ==========================================
        if (!Schema::hasTable('credit_transactions')) {
            Schema::create('credit_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_after', 15, 2);
                $table->string('source')->nullable();
                $table->text('description')->nullable();
                $table->string('reference')->nullable();
                $table->morphs('referenceable');
                $table->timestamps();

                $table->index(['user_id', 'type', 'created_at']);
            });
        }

        // ==========================================
        // ARTIST REVENUES
        // ==========================================
        if (!Schema::hasTable('artist_revenues')) {
            Schema::create('artist_revenues', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
                $table->string('revenue_type');
                $table->morphs('sourceable');
                $table->decimal('amount_ugx', 15, 2)->default(0);
                $table->decimal('amount_usd', 15, 2)->default(0);
                $table->decimal('platform_fee', 15, 2)->default(0);
                $table->decimal('net_amount', 15, 2)->default(0);
                $table->string('status')->default('pending');
                $table->date('revenue_date');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['artist_id', 'status', 'revenue_date']);
            });
        }

        // ==========================================
        // ROYALTY SPLITS
        // ==========================================
        if (!Schema::hasTable('royalty_splits')) {
            Schema::create('royalty_splits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('song_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('percentage', 5, 2);
                $table->boolean('applies_to_streaming')->default(true);
                $table->boolean('applies_to_downloads')->default(true);
                $table->string('status')->default('active');
                $table->timestamps();

                $table->unique(['song_id', 'recipient_id']);
            });
        }

        // ==========================================
        // SUBSCRIPTION PLANS
        // ==========================================
        if (!Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->text('description')->nullable();
                $table->string('tier');
                $table->decimal('price_monthly', 12, 2)->default(0);
                $table->decimal('price_yearly', 12, 2)->default(0);
                $table->string('currency', 10)->default('UGX');
                $table->json('features')->nullable();
                $table->integer('downloads_per_day')->nullable();
                $table->string('streaming_quality')->default('128');
                $table->boolean('has_ads')->default(true);
                $table->boolean('offline_mode')->default(false);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // ==========================================
        // USER SUBSCRIPTIONS
        // ==========================================
        if (!Schema::hasTable('user_subscriptions')) {
            Schema::create('user_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
                $table->string('billing_period');
                $table->datetime('starts_at');
                $table->datetime('ends_at')->nullable();
                $table->datetime('cancelled_at')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'user_subscriptions',
            'subscription_plans',
            'royalty_splits',
            'artist_revenues',
            'credit_transactions',
            'user_credits',
            'payments',
            'event_attendees',
            'event_tickets',
            'event_locations',
            'events',
            'playlist_song',
            'playlists',
            'downloads',
            'play_history',
            'user_follows',
            'likes',
            'songs',
            'albums',
            'artists',
            'genres',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
