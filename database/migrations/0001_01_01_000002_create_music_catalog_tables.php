<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('like_count')->default(0);
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
            $table->bigInteger('total_plays')->default(0);
            $table->integer('career_start_year')->nullable();
            $table->text('influences')->nullable();
            $table->string('record_label')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->string('verification_status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->integer('total_songs_count')->default(0);
            $table->integer('total_albums_count')->default(0);
            $table->bigInteger('total_plays_count')->default(0);
            $table->bigInteger('total_plays_cached')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('total_revenue_cached', 15, 2)->default(0);
            $table->decimal('earnings_balance', 15, 2)->default(0);
            $table->string('payout_phone_number')->nullable();
            $table->timestamp('stats_last_updated_at')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(20);
            $table->boolean('require_approval')->default(true);
            $table->boolean('distribution_suspended')->default(false);
            $table->string('verification_badge')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'is_verified']);
            $table->index(['is_featured', 'total_plays']);
        });

        Schema::create('artist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->string('stage_name')->nullable();
            $table->string('real_name')->nullable();
            $table->string('nin_number')->nullable();
            $table->string('verification_status')->default('pending');
            $table->json('verification_documents')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('bio')->nullable();
            $table->string('website')->nullable();
            $table->json('social_links')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('manager_contact')->nullable();
            $table->json('genres')->nullable();
            $table->json('languages')->nullable();
            $table->string('record_label')->nullable();
            $table->string('publishing_company')->nullable();
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('career_stage')->nullable();
            $table->string('mobile_money_provider')->nullable();
            $table->string('mobile_money_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('payout_method')->default('mobile_money');
            $table->decimal('minimum_payout', 12, 2)->default(0);
            $table->decimal('total_credits_earned', 15, 2)->default(0);
            $table->decimal('total_money_earned', 15, 2)->default(0);
            $table->boolean('money_payout_enabled')->default(false);
            $table->timestamp('money_payout_unlocked_at')->nullable();
            $table->boolean('auto_distribute')->default(false);
            $table->json('distribution_preferences')->nullable();
            $table->decimal('distribution_fee_percentage', 5, 2)->default(0);
            $table->boolean('public_stats')->default(true);
            $table->boolean('detailed_analytics')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('profile_completed')->default(false);
            $table->timestamps();

            $table->index(['verification_status', 'is_active']);
            $table->index('artist_id');
        });

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
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('UGX');
            $table->integer('play_count')->default(0);
            $table->integer('like_count')->default(0);
            $table->integer('download_count')->default(0);
            $table->foreignId('genre_id')->nullable()->constrained('genres')->nullOnDelete();
            $table->string('type')->nullable();
            $table->boolean('is_free')->default(true);
            $table->unsignedInteger('total_tracks')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->string('upc_code')->nullable();
            $table->string('copyright')->nullable();
            $table->string('publisher')->nullable();
            $table->string('record_label')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['artist_id', 'status']);
            $table->index('primary_genre_id');
            $table->index('release_date');
        });

        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
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
            $table->string('file_format', 10)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->integer('duration_seconds')->default(0);
            $table->foreignId('primary_genre_id')->nullable()->constrained('genres')->nullOnDelete();
            $table->date('release_date')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_reason')->nullable();
            $table->string('visibility', 20)->default('public');
            $table->boolean('is_explicit')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_free')->default(true);
            $table->boolean('is_downloadable')->default(true);
            $table->boolean('is_streamable')->default(true);
            $table->json('processing_status')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('UGX');
            $table->json('featured_artists')->nullable();
            $table->string('composer')->nullable();
            $table->string('producer')->nullable();
            $table->bigInteger('play_count')->default(0);
            $table->integer('like_count')->default(0);
            $table->integer('download_count')->default(0);
            $table->integer('track_number')->nullable();
            $table->timestamp('preview_url')->nullable();
            $table->integer('credits_price')->default(0);
            $table->integer('share_count')->default(0);
            $table->string('lyrics_language', 10)->nullable();
            $table->string('publisher')->nullable();
            $table->string('copyright')->nullable();
            $table->string('isrc')->nullable();
            $table->unsignedInteger('bpm')->nullable();
            $table->string('key_signature')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['artist_id', 'status']);
            $table->index(['album_id', 'track_number']);
            $table->index(['is_featured', 'play_count']);
            $table->index('primary_genre_id');
            $table->index('release_date');
        });

        Schema::create('song_genres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['song_id', 'genre_id']);
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('likeable');
            $table->string('type', 20)->default('like');
            $table->timestamps();
            $table->unique(['user_id', 'likeable_type', 'likeable_id', 'type'], 'likes_unique');
        });

        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('following_id')->nullable();
            $table->nullableMorphs('followable');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->foreignId('followed_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['follower_id', 'following_id']);
        });

        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('like_count')->default(0);
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
            $table->string('visibility')->default('public');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('total_tracks')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->unsignedInteger('play_count')->default(0);
            $table->unsignedInteger('follower_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'visibility']);
        });

        Schema::create('playlist_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
            $table->unique(['playlist_id', 'song_id']);
        });

        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('downloadable');
            $table->string('quality')->default('320');
            $table->string('format')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
        });

        Schema::create('play_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->foreignId('album_id')->nullable()->constrained('albums')->nullOnDelete();
            $table->timestamp('played_at')->useCurrent();
            $table->integer('duration_played_seconds')->nullable();
            $table->integer('duration_played')->default(0);
            $table->boolean('completed')->default(false);
            $table->boolean('skipped')->default(false);
            $table->decimal('completion_percentage', 5, 2)->nullable();
            $table->string('source')->nullable();
            $table->string('device_type')->nullable();
            $table->string('quality', 10)->nullable();
            $table->timestamps();
            $table->integer('duration_listened')->nullable();
            $table->foreignId('playlist_id')->nullable()->constrained('playlists')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->index(['user_id', 'played_at']);
            $table->index(['song_id', 'played_at']);
            $table->index(['user_id', 'song_id']);
        });

        Schema::create('event_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 200);
            $table->string('slug')->nullable()->unique();
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

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('like_count')->default(0);
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organizer_type')->default('user');
            $table->foreignId('event_location_id')->nullable()->constrained('event_locations')->nullOnDelete();
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
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('doors_open_at')->nullable();
            $table->dateTime('registration_deadline')->nullable();
            $table->string('timezone')->default('Africa/Kampala');
            $table->string('status')->default('draft');
            $table->string('visibility')->default('public');
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
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
            $table->unsignedBigInteger('loyalty_card_id')->nullable();
            $table->integer('tier_early_access_hours')->nullable();
            $table->boolean('hide_from_non_qualifying')->default(false);
            $table->text('cancellation_policy')->nullable();
            $table->text('refund_policy')->nullable();
            $table->json('requirements')->nullable();
            $table->json('contact_info')->nullable();
            $table->string('website')->nullable();
            $table->json('social_links')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->integer('review_count')->default(0);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('max_price', 12, 2)->nullable();
            $table->integer('attendees_count')->default(0);
            $table->boolean('is_online')->default(false);
            $table->string('online_url')->nullable();
            $table->string('registration_url')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->json('ticket_info')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'starts_at']);
            $table->index(['is_featured', 'starts_at']);
        });

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
            $table->integer('quantity_reserved')->default(0);
            $table->integer('min_per_order')->default(1);
            $table->integer('max_per_order')->default(10);
            $table->dateTime('sale_starts_at')->nullable();
            $table->dateTime('sale_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('required_loyalty_tier')->nullable();
            $table->integer('tier_early_access_hours')->nullable();
            $table->json('tier_discounts')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_attendees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('confirmation_code')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('event_tickets')->nullOnDelete();
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();
            $table->string('attendee_phone')->nullable();
            $table->decimal('price_paid_ugx', 12, 2)->default(0);
            $table->integer('price_paid_credits')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('status')->default('registered');
            $table->timestamp('confirmed_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('qr_code')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_reference')->nullable();
            $table->string('payment_status')->default('pending');
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attended_at')->nullable();
            $table->json('attendee_metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'user_id']);
        });

        Schema::create('event_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'event_attendees',
            'event_interests',
            'event_tickets',
            'events',
            'event_locations',
            'play_histories',
            'downloads',
            'playlist_songs',
            'playlists',
            'user_follows',
            'likes',
            'song_genres',
            'songs',
            'albums',
            'artist_profiles',
            'artists',
            'genres',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
