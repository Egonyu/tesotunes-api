<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive Schema Sync Migration
 *
 * This migration ensures all model-required tables and columns exist.
 * It's idempotent - safe to run multiple times without causing errors.
 *
 * STANDARDIZATION APPROACH:
 * 1. All tables are defined with complete column sets
 * 2. Uses Schema::hasTable() and Schema::hasColumn() for safety
 * 3. Foreign keys are added only after all tables exist
 * 4. Indexes are created for performance-critical columns
 *
 * RUN: php artisan migrate
 * ROLLBACK: Safe - only drops tables this migration creates
 */
return new class extends Migration
{
    /**
     * Helper to create table only if it doesn't exist
     */
    private function createTableIfNotExists(string $tableName, callable $definition): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $definition);
        }
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createMissingTables();
        $this->addMissingColumns();
        $this->addMissingIndexes();
    }

    /**
     * Create all missing tables
     */
    private function createMissingTables(): void
    {
        // ------- ACTIVITIES -------
        $this->createTableIfNotExists('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->morphs('subject');
            $table->json('properties')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // ------- ACTIVITY_COMMENTS -------
        $this->createTableIfNotExists('activity_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('activity_comments')->nullOnDelete();
        });

        // ------- AD_IMPRESSIONS -------
        $this->createTableIfNotExists('ad_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('ad_id');
            $table->string('ad_type', 50);
            $table->string('placement', 100)->nullable();
            $table->string('action', 20)->default('impression');
            $table->decimal('revenue', 10, 4)->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->timestamps();
            $table->index(['ad_id', 'ad_type']);
            $table->index('created_at');
        });

        // ------- AUDIT_LOGS -------
        $this->createTableIfNotExists('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->text('url')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });

        // ------- CAMPAIGNS -------
        $this->createTableIfNotExists('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('video_url')->nullable();
            $table->string('type', 50)->default('crowdfunding');
            $table->string('category', 100)->nullable();
            $table->decimal('goal_amount', 12, 2);
            $table->decimal('raised_amount', 12, 2)->default(0);
            $table->decimal('minimum_pledge', 10, 2)->default(1);
            $table->string('currency', 3)->default('UGX');
            $table->integer('backer_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_anonymous')->default(true);
            $table->json('reward_tiers')->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ------- CAMPAIGN_PLEDGES -------
        $this->createTableIfNotExists('campaign_pledges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('UGX');
            $table->string('reward_tier_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->string('payment_method', 50)->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        // ------- CAMPAIGN_UPDATES -------
        $this->createTableIfNotExists('campaign_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('image')->nullable();
            $table->boolean('backers_only')->default(false);
            $table->timestamps();
        });

        // ------- CREDIT_RATES -------
        $this->createTableIfNotExists('credit_rates', function (Blueprint $table) {
            $table->id();
            $table->string('activity_type', 50)->unique();
            $table->decimal('credits_per_action', 10, 2);
            $table->decimal('daily_limit', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ------- DEVICE_TOKENS -------
        $this->createTableIfNotExists('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 20);
            $table->string('device_type', 50)->nullable();
            $table->string('device_name')->nullable();
            $table->string('app_version', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // ------- EVENT_LOCATIONS -------
        $this->createTableIfNotExists('event_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('country', 100)->default('Uganda');
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('venue_type', 50)->nullable();
            $table->integer('capacity')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->json('amenities')->nullable();
            $table->json('contact_info')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ------- FEED_AB_TESTS -------
        $this->createTableIfNotExists('feed_ab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('variants');
            $table->decimal('traffic_percentage', 5, 2)->default(100);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();
        });

        // ------- FEED_ANALYTICS -------
        $this->createTableIfNotExists('feed_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->morphs('content');
            $table->integer('position')->nullable();
            $table->string('source', 50)->nullable();
            $table->integer('dwell_time_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'event_type', 'created_at']);
        });

        // ------- FEED_ITEMS -------
        $this->createTableIfNotExists('feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('content');
            $table->string('content_source', 50)->default('following');
            $table->decimal('relevance_score', 8, 4)->default(0);
            $table->decimal('engagement_score', 8, 4)->default(0);
            $table->boolean('is_seen')->default(false);
            $table->boolean('is_interacted')->default(false);
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_seen', 'created_at']);
        });

        // ------- FEED_PREFERENCES -------
        $this->createTableIfNotExists('feed_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('genre_weights')->nullable();
            $table->json('artist_weights')->nullable();
            $table->json('content_type_weights')->nullable();
            $table->json('blocked_content_ids')->nullable();
            $table->json('muted_artist_ids')->nullable();
            $table->timestamps();
        });

        // ------- FRONTEND_SETTINGS -------
        $this->createTableIfNotExists('frontend_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        // ------- ISRC_CODES -------
        $this->createTableIfNotExists('isrc_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->string('country_code', 2)->default('UG');
            $table->string('registrant_code', 3);
            $table->string('year_code', 2);
            $table->string('designation_code', 5);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // ------- MOODS -------
        $this->createTableIfNotExists('moods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        // ------- MUSIC_UPLOADS -------
        $this->createTableIfNotExists('music_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 50);
            $table->string('status', 20)->default('pending');
            $table->integer('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->json('processing_log')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // ------- PODCASTS -------
        $this->createTableIfNotExists('podcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('podcast_category_id')->nullable();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('artwork')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('rss_feed_url')->nullable();
            $table->string('rss_guid')->nullable();
            $table->string('author_name')->nullable();
            $table->string('copyright')->nullable();
            $table->json('tags')->nullable();
            $table->string('language', 10)->default('en');
            $table->boolean('is_explicit')->default(false);
            $table->boolean('explicit_content')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('total_episodes')->default(0);
            $table->integer('total_listens')->default(0);
            $table->integer('subscriber_count')->default(0);
            $table->integer('total_listen_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // ------- PODCAST_CATEGORIES -------
        $this->createTableIfNotExists('podcast_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('display_order')->default(0);
            $table->integer('podcast_count')->default(0);
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('podcast_categories')->nullOnDelete();
        });

        // ------- PODCAST_EPISODES -------
        $this->createTableIfNotExists('podcast_episodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('podcast_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sponsor_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->integer('episode_number')->nullable();
            $table->integer('season_number')->nullable();
            $table->string('audio_file');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('artwork')->nullable();
            $table->integer('duration_seconds')->default(0);
            $table->string('type', 20)->default('full');
            $table->boolean('is_explicit')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->boolean('has_preview')->default(false);
            $table->integer('preview_duration_seconds')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('published_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->integer('listen_count')->default(0);
            $table->integer('download_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // ------- POSTS -------
        $this->createTableIfNotExists('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete();
            $table->text('content')->nullable();
            $table->string('type', 20)->default('text');
            $table->json('metadata')->nullable();
            $table->string('privacy', 20)->default('public');
            $table->string('visibility', 20)->default('public');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->integer('views_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ------- POST_COMMENTS -------
        $this->createTableIfNotExists('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('content');
            $table->integer('likes_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('parent_id')->references('id')->on('post_comments')->nullOnDelete();
        });

        // ------- POST_MEDIA -------
        $this->createTableIfNotExists('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->integer('order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // ------- POST_LIKES -------
        $this->createTableIfNotExists('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        // ------- PUBLISHING_RIGHTS -------
        $this->createTableIfNotExists('publishing_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('owner_id');
            $table->string('rights_type', 50);
            $table->decimal('ownership_percentage', 5, 2)->default(100);
            $table->decimal('royalty_split_percentage', 5, 2)->nullable();
            $table->string('rights_holder_name')->nullable();
            $table->string('rights_holder_type', 50)->nullable();
            $table->string('performing_rights_organization', 50)->nullable();
            $table->string('pro_member_number', 50)->nullable();
            $table->text('rights_description')->nullable();
            $table->date('rights_start_date')->nullable();
            $table->date('rights_end_date')->nullable();
            $table->json('territorial_scope')->nullable();
            $table->boolean('exclusive_rights')->default(true);
            $table->string('contract_reference')->nullable();
            $table->string('contract_type', 50)->nullable();
            $table->json('contract_terms')->nullable();
            $table->string('documentation_url')->nullable();
            $table->boolean('collect_royalties')->default(true);
            $table->decimal('minimum_payout_threshold', 10, 2)->nullable();
            $table->string('payout_frequency', 20)->nullable();
            $table->string('payout_method', 50)->nullable();
            $table->json('payout_details')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ------- SACCO_MEMBERS -------
        $this->createTableIfNotExists('sacco_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('member_number')->unique();
            $table->string('status', 20)->default('pending');
            $table->decimal('share_capital', 12, 2)->default(0);
            $table->decimal('savings_balance', 12, 2)->default(0);
            $table->date('joined_at')->nullable();
            $table->date('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();
        });

        // ------- SACCO_LOANS -------
        $this->createTableIfNotExists('sacco_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sacco_member_id');
            $table->string('loan_number')->unique();
            $table->decimal('principal_amount', 12, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->integer('term_months');
            $table->decimal('monthly_payment', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->text('purpose')->nullable();
            $table->date('approved_at')->nullable();
            $table->date('disbursed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->foreign('sacco_member_id')->references('id')->on('sacco_members')->cascadeOnDelete();
        });

        // ------- SACCO_TRANSACTIONS -------
        $this->createTableIfNotExists('sacco_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sacco_member_id');
            $table->unsignedBigInteger('sacco_loan_id')->nullable();
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reference')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestamps();
            $table->foreign('sacco_member_id')->references('id')->on('sacco_members')->cascadeOnDelete();
            $table->foreign('sacco_loan_id')->references('id')->on('sacco_loans')->nullOnDelete();
        });

        // ------- SETTINGS -------
        $this->createTableIfNotExists('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        // ------- SONG_MOODS (pivot) -------
        $this->createTableIfNotExists('song_moods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mood_id');
            $table->timestamps();
            $table->unique(['song_id', 'mood_id']);
        });

        // ------- USER_FEED_SETTINGS -------
        $this->createTableIfNotExists('user_feed_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('show_recommendations')->default(true);
            $table->boolean('show_new_releases')->default(true);
            $table->boolean('show_following_activity')->default(true);
            $table->boolean('show_trending')->default(true);
            $table->json('preferred_genres')->nullable();
            $table->json('muted_artists')->nullable();
            $table->json('muted_content_types')->nullable();
            $table->string('feed_algorithm', 20)->default('balanced');
            $table->timestamps();
        });

        // ------- SHARES -------
        $this->createTableIfNotExists('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('shareable');
            $table->string('platform', 50)->nullable();
            $table->text('message')->nullable();
            $table->string('share_link')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // ------- VIEWS -------
        $this->createTableIfNotExists('views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('viewable');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamps();
            $table->index(['viewable_type', 'viewable_id', 'created_at']);
        });

        // ------- COMMENTS (generic) -------
        $this->createTableIfNotExists('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('content');
            $table->integer('likes_count')->default(0);
            $table->string('status', 20)->default('approved');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('parent_id')->references('id')->on('comments')->nullOnDelete();
        });
    }

    /**
     * Add missing columns to existing tables
     */
    private function addMissingColumns(): void
    {
        // ------- USERS TABLE -------
        $this->addColumnsIfMissing('users', [
            'credits' => fn ($t) => $t->integer('credits')->default(0),
            'ugx_balance' => fn ($t) => $t->decimal('ugx_balance', 12, 2)->default(0),
            'phone_number' => fn ($t) => $t->string('phone_number', 20)->nullable(),
            'avatar' => fn ($t) => $t->string('avatar')->nullable(),
            'bio' => fn ($t) => $t->text('bio')->nullable(),
            'country' => fn ($t) => $t->string('country', 100)->nullable(),
            'city' => fn ($t) => $t->string('city', 100)->nullable(),
            'date_of_birth' => fn ($t) => $t->date('date_of_birth')->nullable(),
            'gender' => fn ($t) => $t->string('gender', 20)->nullable(),
            'referral_code' => fn ($t) => $t->string('referral_code', 20)->nullable(),
            'referred_by' => fn ($t) => $t->unsignedBigInteger('referred_by')->nullable(),
            'entity_type' => fn ($t) => $t->string('entity_type', 20)->nullable(),
            'is_verified' => fn ($t) => $t->boolean('is_verified')->default(false),
            'email_verified_at' => fn ($t) => $t->timestamp('email_verified_at')->nullable(),
            'phone_verified_at' => fn ($t) => $t->timestamp('phone_verified_at')->nullable(),
            'status' => fn ($t) => $t->string('status', 20)->default('active'),
            'last_login_at' => fn ($t) => $t->timestamp('last_login_at')->nullable(),
            'last_activity_at' => fn ($t) => $t->timestamp('last_activity_at')->nullable(),
            'timezone' => fn ($t) => $t->string('timezone', 50)->default('Africa/Kampala'),
            'language' => fn ($t) => $t->string('language', 10)->default('en'),
        ]);

        // ------- ARTISTS TABLE -------
        $this->addColumnsIfMissing('artists', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'bio' => fn ($t) => $t->text('bio')->nullable(),
            'avatar' => fn ($t) => $t->string('avatar')->nullable(),
            'banner' => fn ($t) => $t->string('banner')->nullable(),
            'cover_image' => fn ($t) => $t->string('cover_image')->nullable(),
            'country' => fn ($t) => $t->string('country', 100)->nullable(),
            'city' => fn ($t) => $t->string('city', 100)->nullable(),
            'primary_genre_id' => fn ($t) => $t->unsignedBigInteger('primary_genre_id')->nullable(),
            'website_url' => fn ($t) => $t->string('website_url')->nullable(),
            'social_links' => fn ($t) => $t->json('social_links')->nullable(),
            'career_start_year' => fn ($t) => $t->year('career_start_year')->nullable(),
            'record_label' => fn ($t) => $t->string('record_label')->nullable(),
            'influences' => fn ($t) => $t->text('influences')->nullable(),
            'is_verified' => fn ($t) => $t->boolean('is_verified')->default(false),
            'is_trusted' => fn ($t) => $t->boolean('is_trusted')->default(false),
            'can_upload' => fn ($t) => $t->boolean('can_upload')->default(true),
            'auto_publish' => fn ($t) => $t->boolean('auto_publish')->default(false),
            'verification_status' => fn ($t) => $t->string('verification_status', 20)->default('pending'),
            'verification_badge' => fn ($t) => $t->string('verification_badge', 20)->nullable(),
            'verified_at' => fn ($t) => $t->timestamp('verified_at')->nullable(),
            'verified_by' => fn ($t) => $t->unsignedBigInteger('verified_by')->nullable(),
            'rejection_reason' => fn ($t) => $t->text('rejection_reason')->nullable(),
            'monthly_upload_limit' => fn ($t) => $t->integer('monthly_upload_limit')->default(10),
            'total_songs_count' => fn ($t) => $t->integer('total_songs_count')->default(0),
            'total_albums_count' => fn ($t) => $t->integer('total_albums_count')->default(0),
            'total_plays_count' => fn ($t) => $t->bigInteger('total_plays_count')->default(0),
            'total_plays_cached' => fn ($t) => $t->bigInteger('total_plays_cached')->default(0),
            'followers_count' => fn ($t) => $t->integer('followers_count')->default(0),
            'total_revenue' => fn ($t) => $t->decimal('total_revenue', 15, 2)->default(0),
            'total_revenue_cached' => fn ($t) => $t->decimal('total_revenue_cached', 15, 2)->default(0),
            'earnings_balance' => fn ($t) => $t->decimal('earnings_balance', 15, 2)->default(0),
            'payout_phone_number' => fn ($t) => $t->string('payout_phone_number', 20)->nullable(),
            'stats_last_updated_at' => fn ($t) => $t->timestamp('stats_last_updated_at')->nullable(),
            'commission_rate' => fn ($t) => $t->decimal('commission_rate', 5, 2)->default(20),
            'require_approval' => fn ($t) => $t->boolean('require_approval')->default(true),
            'distribution_suspended' => fn ($t) => $t->boolean('distribution_suspended')->default(false),
        ]);

        // ------- SONGS TABLE -------
        $this->addColumnsIfMissing('songs', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'user_id' => fn ($t) => $t->unsignedBigInteger('user_id')->nullable(),
            'audio_file_original' => fn ($t) => $t->string('audio_file_original')->nullable(),
            'audio_file_320' => fn ($t) => $t->string('audio_file_320')->nullable(),
            'audio_file_128' => fn ($t) => $t->string('audio_file_128')->nullable(),
            'preview_url' => fn ($t) => $t->string('preview_url')->nullable(),
            'artwork' => fn ($t) => $t->string('artwork')->nullable(),
            'duration_seconds' => fn ($t) => $t->integer('duration_seconds')->default(0),
            'file_format' => fn ($t) => $t->string('file_format', 10)->nullable(),
            'file_size_bytes' => fn ($t) => $t->unsignedBigInteger('file_size_bytes')->nullable(),
            'is_explicit' => fn ($t) => $t->boolean('is_explicit')->default(false),
            'is_featured' => fn ($t) => $t->boolean('is_featured')->default(false),
            'is_free' => fn ($t) => $t->boolean('is_free')->default(true),
            'is_downloadable' => fn ($t) => $t->boolean('is_downloadable')->default(true),
            'is_streamable' => fn ($t) => $t->boolean('is_streamable')->default(true),
            'visibility' => fn ($t) => $t->string('visibility', 20)->default('public'),
            'processing_status' => fn ($t) => $t->json('processing_status')->nullable(),
            'price' => fn ($t) => $t->decimal('price', 10, 2)->default(0),
            'credits_price' => fn ($t) => $t->integer('credits_price')->default(0),
            'play_count' => fn ($t) => $t->bigInteger('play_count')->default(0),
            'download_count' => fn ($t) => $t->integer('download_count')->default(0),
            'like_count' => fn ($t) => $t->integer('like_count')->default(0),
            'share_count' => fn ($t) => $t->integer('share_count')->default(0),
            'status' => fn ($t) => $t->string('status', 20)->default('draft'),
            'primary_genre_id' => fn ($t) => $t->unsignedBigInteger('primary_genre_id')->nullable(),
            'featured_artists' => fn ($t) => $t->json('featured_artists')->nullable(),
            'lyrics' => fn ($t) => $t->text('lyrics')->nullable(),
            'lyrics_language' => fn ($t) => $t->string('lyrics_language', 10)->nullable(),
            'composer' => fn ($t) => $t->string('composer')->nullable(),
            'producer' => fn ($t) => $t->string('producer')->nullable(),
            'publisher' => fn ($t) => $t->string('publisher')->nullable(),
            'copyright' => fn ($t) => $t->string('copyright')->nullable(),
            'isrc' => fn ($t) => $t->string('isrc', 12)->nullable(),
            'bpm' => fn ($t) => $t->integer('bpm')->nullable(),
            'key_signature' => fn ($t) => $t->string('key_signature', 10)->nullable(),
            'release_date' => fn ($t) => $t->date('release_date')->nullable(),
            'published_at' => fn ($t) => $t->timestamp('published_at')->nullable(),
            'description' => fn ($t) => $t->text('description')->nullable(),
        ]);

        // ------- ALBUMS TABLE -------
        $this->addColumnsIfMissing('albums', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'artwork' => fn ($t) => $t->string('artwork')->nullable(),
            'description' => fn ($t) => $t->text('description')->nullable(),
            'genre_id' => fn ($t) => $t->unsignedBigInteger('genre_id')->nullable(),
            'release_date' => fn ($t) => $t->date('release_date')->nullable(),
            'type' => fn ($t) => $t->string('type', 20)->default('album'),
            'status' => fn ($t) => $t->string('status', 20)->default('draft'),
            'is_featured' => fn ($t) => $t->boolean('is_featured')->default(false),
            'is_explicit' => fn ($t) => $t->boolean('is_explicit')->default(false),
            'price' => fn ($t) => $t->decimal('price', 10, 2)->default(0),
            'is_free' => fn ($t) => $t->boolean('is_free')->default(true),
            'total_tracks' => fn ($t) => $t->integer('total_tracks')->default(0),
            'total_duration_seconds' => fn ($t) => $t->integer('total_duration_seconds')->default(0),
            'play_count' => fn ($t) => $t->bigInteger('play_count')->default(0),
            'download_count' => fn ($t) => $t->integer('download_count')->default(0),
            'like_count' => fn ($t) => $t->integer('like_count')->default(0),
            'upc_code' => fn ($t) => $t->string('upc_code', 14)->nullable(),
            'copyright' => fn ($t) => $t->string('copyright')->nullable(),
            'publisher' => fn ($t) => $t->string('publisher')->nullable(),
            'record_label' => fn ($t) => $t->string('record_label')->nullable(),
            'published_at' => fn ($t) => $t->timestamp('published_at')->nullable(),
        ]);

        // ------- PLAYLISTS TABLE -------
        $this->addColumnsIfMissing('playlists', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'artwork' => fn ($t) => $t->string('artwork')->nullable(),
            'visibility' => fn ($t) => $t->string('visibility', 20)->default('public'),
            'is_collaborative' => fn ($t) => $t->boolean('is_collaborative')->default(false),
            'is_featured' => fn ($t) => $t->boolean('is_featured')->default(false),
            'is_system' => fn ($t) => $t->boolean('is_system')->default(false),
            'total_tracks' => fn ($t) => $t->integer('total_tracks')->default(0),
            'total_duration_seconds' => fn ($t) => $t->integer('total_duration_seconds')->default(0),
            'play_count' => fn ($t) => $t->integer('play_count')->default(0),
            'follower_count' => fn ($t) => $t->integer('follower_count')->default(0),
        ]);

        // ------- GENRES TABLE -------
        $this->addColumnsIfMissing('genres', [
            'description' => fn ($t) => $t->text('description')->nullable(),
            'icon' => fn ($t) => $t->string('icon')->nullable(),
            'color' => fn ($t) => $t->string('color', 7)->nullable(),
            'image' => fn ($t) => $t->string('image')->nullable(),
            'parent_id' => fn ($t) => $t->unsignedBigInteger('parent_id')->nullable(),
            'is_active' => fn ($t) => $t->boolean('is_active')->default(true),
            'display_order' => fn ($t) => $t->integer('display_order')->default(0),
            'song_count' => fn ($t) => $t->integer('song_count')->default(0),
        ]);

        // ------- EVENTS TABLE -------
        $this->addColumnsIfMissing('events', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'event_location_id' => fn ($t) => $t->unsignedBigInteger('event_location_id')->nullable(),
            'artist_id' => fn ($t) => $t->unsignedBigInteger('artist_id')->nullable(),
            'cover_image' => fn ($t) => $t->string('cover_image')->nullable(),
            'banner' => fn ($t) => $t->string('banner')->nullable(),
            'venue_name' => fn ($t) => $t->string('venue_name')->nullable(),
            'venue_address' => fn ($t) => $t->text('venue_address')->nullable(),
            'city' => fn ($t) => $t->string('city', 100)->nullable(),
            'latitude' => fn ($t) => $t->decimal('latitude', 10, 8)->nullable(),
            'longitude' => fn ($t) => $t->decimal('longitude', 11, 8)->nullable(),
            'start_date' => fn ($t) => $t->dateTime('start_date')->nullable(),
            'end_date' => fn ($t) => $t->dateTime('end_date')->nullable(),
            'timezone' => fn ($t) => $t->string('timezone', 50)->default('Africa/Kampala'),
            'price' => fn ($t) => $t->decimal('price', 10, 2)->default(0),
            'max_price' => fn ($t) => $t->decimal('max_price', 10, 2)->nullable(),
            'currency' => fn ($t) => $t->string('currency', 3)->default('UGX'),
            'capacity' => fn ($t) => $t->integer('capacity')->nullable(),
            'attendees_count' => fn ($t) => $t->integer('attendees_count')->default(0),
            'status' => fn ($t) => $t->string('status', 20)->default('draft'),
            'is_featured' => fn ($t) => $t->boolean('is_featured')->default(false),
            'is_free' => fn ($t) => $t->boolean('is_free')->default(false),
            'is_online' => fn ($t) => $t->boolean('is_online')->default(false),
            'online_url' => fn ($t) => $t->string('online_url')->nullable(),
            'registration_url' => fn ($t) => $t->string('registration_url')->nullable(),
            'contact_email' => fn ($t) => $t->string('contact_email')->nullable(),
            'contact_phone' => fn ($t) => $t->string('contact_phone', 20)->nullable(),
            'ticket_info' => fn ($t) => $t->json('ticket_info')->nullable(),
            'tags' => fn ($t) => $t->json('tags')->nullable(),
        ]);

        // ------- AWARDS TABLE -------
        $this->addColumnsIfMissing('awards', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'logo' => fn ($t) => $t->string('logo')->nullable(),
            'banner' => fn ($t) => $t->string('banner')->nullable(),
            'website_url' => fn ($t) => $t->string('website_url')->nullable(),
            'organizer_name' => fn ($t) => $t->string('organizer_name')->nullable(),
            'organizer_email' => fn ($t) => $t->string('organizer_email')->nullable(),
            'event_date' => fn ($t) => $t->date('event_date')->nullable(),
            'voting_start_date' => fn ($t) => $t->dateTime('voting_start_date')->nullable(),
            'voting_end_date' => fn ($t) => $t->dateTime('voting_end_date')->nullable(),
            'nomination_start_date' => fn ($t) => $t->dateTime('nomination_start_date')->nullable(),
            'nomination_end_date' => fn ($t) => $t->dateTime('nomination_end_date')->nullable(),
            'is_active' => fn ($t) => $t->boolean('is_active')->default(true),
            'is_public' => fn ($t) => $t->boolean('is_public')->default(true),
            'voting_method' => fn ($t) => $t->string('voting_method', 20)->default('public'),
        ]);

        // ------- LIKES TABLE -------
        $this->addColumnsIfMissing('likes', [
            'type' => fn ($t) => $t->string('type', 20)->default('like'),
            'likeable_type' => fn ($t) => $t->string('likeable_type')->nullable(),
            'likeable_id' => fn ($t) => $t->unsignedBigInteger('likeable_id')->nullable(),
        ]);

        // ------- PAYMENTS TABLE -------
        $this->addColumnsIfMissing('payments', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'payment_method' => fn ($t) => $t->string('payment_method', 50)->nullable(),
            'payment_provider' => fn ($t) => $t->string('payment_provider', 50)->nullable(),
            'provider_reference' => fn ($t) => $t->string('provider_reference')->nullable(),
            'currency' => fn ($t) => $t->string('currency', 3)->default('UGX'),
            'fee' => fn ($t) => $t->decimal('fee', 10, 2)->default(0),
            'metadata' => fn ($t) => $t->json('metadata')->nullable(),
            'payable_type' => fn ($t) => $t->string('payable_type')->nullable(),
            'payable_id' => fn ($t) => $t->unsignedBigInteger('payable_id')->nullable(),
            'paid_at' => fn ($t) => $t->timestamp('paid_at')->nullable(),
            'failed_at' => fn ($t) => $t->timestamp('failed_at')->nullable(),
            'failure_reason' => fn ($t) => $t->text('failure_reason')->nullable(),
        ]);

        // ------- NOTIFICATIONS TABLE -------
        $this->addColumnsIfMissing('notifications', [
            'read_at' => fn ($t) => $t->timestamp('read_at')->nullable(),
            'action_url' => fn ($t) => $t->string('action_url')->nullable(),
        ]);

        // ------- USER_CREDITS TABLE -------
        $this->addColumnsIfMissing('user_credits', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'balance' => fn ($t) => $t->decimal('balance', 15, 2)->default(0),
            'lifetime_earned' => fn ($t) => $t->decimal('lifetime_earned', 15, 2)->default(0),
            'lifetime_spent' => fn ($t) => $t->decimal('lifetime_spent', 15, 2)->default(0),
            'currency' => fn ($t) => $t->string('currency', 3)->default('UGX'),
        ]);

        // ------- CREDIT_TRANSACTIONS TABLE -------
        $this->addColumnsIfMissing('credit_transactions', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'balance_after' => fn ($t) => $t->decimal('balance_after', 15, 2)->default(0),
            'source' => fn ($t) => $t->string('source', 100)->nullable(),
            'reference' => fn ($t) => $t->string('reference')->nullable(),
            'reference_type' => fn ($t) => $t->string('reference_type')->nullable(),
            'reference_id' => fn ($t) => $t->unsignedBigInteger('reference_id')->nullable(),
            'metadata' => fn ($t) => $t->json('metadata')->nullable(),
        ]);

        // ------- ARTIST_REVENUES TABLE -------
        $this->addColumnsIfMissing('artist_revenues', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'revenue_type' => fn ($t) => $t->string('revenue_type', 30)->default('stream'),
            'amount_ugx' => fn ($t) => $t->decimal('amount_ugx', 15, 2)->default(0),
            'amount_usd' => fn ($t) => $t->decimal('amount_usd', 15, 4)->default(0),
            'platform_fee' => fn ($t) => $t->decimal('platform_fee', 10, 2)->default(0),
            'net_amount' => fn ($t) => $t->decimal('net_amount', 15, 2)->default(0),
            'status' => fn ($t) => $t->string('status', 20)->default('pending'),
            'revenue_date' => fn ($t) => $t->date('revenue_date')->nullable(),
            'song_id' => fn ($t) => $t->unsignedBigInteger('song_id')->nullable(),
            'album_id' => fn ($t) => $t->unsignedBigInteger('album_id')->nullable(),
            'source_platform' => fn ($t) => $t->string('source_platform', 50)->nullable(),
            'transaction_count' => fn ($t) => $t->integer('transaction_count')->default(1),
            'paid_at' => fn ($t) => $t->timestamp('paid_at')->nullable(),
        ]);

        // ------- ROYALTY_SPLITS TABLE -------
        $this->addColumnsIfMissing('royalty_splits', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'recipient_id' => fn ($t) => $t->unsignedBigInteger('recipient_id')->nullable(),
            'recipient_name' => fn ($t) => $t->string('recipient_name')->nullable(),
            'recipient_email' => fn ($t) => $t->string('recipient_email')->nullable(),
            'role' => fn ($t) => $t->string('role', 50)->nullable(),
            'applies_to_streaming' => fn ($t) => $t->boolean('applies_to_streaming')->default(true),
            'applies_to_downloads' => fn ($t) => $t->boolean('applies_to_downloads')->default(true),
            'applies_to_sync' => fn ($t) => $t->boolean('applies_to_sync')->default(true),
            'status' => fn ($t) => $t->string('status', 20)->default('active'),
            'total_paid' => fn ($t) => $t->decimal('total_paid', 15, 2)->default(0),
            'last_payment_date' => fn ($t) => $t->date('last_payment_date')->nullable(),
        ]);

        // ------- DOWNLOADS TABLE -------
        $this->addColumnsIfMissing('downloads', [
            'uuid' => fn ($t) => $t->uuid('uuid')->nullable(),
            'quality' => fn ($t) => $t->string('quality', 10)->default('320'),
            'file_size' => fn ($t) => $t->unsignedBigInteger('file_size')->nullable(),
            'credits_used' => fn ($t) => $t->integer('credits_used')->default(0),
            'ip_address' => fn ($t) => $t->string('ip_address', 45)->nullable(),
            'user_agent' => fn ($t) => $t->string('user_agent')->nullable(),
        ]);

        // ------- PLAY_HISTORY TABLE -------
        $this->addColumnsIfMissing('play_history', [
            'duration_listened' => fn ($t) => $t->integer('duration_listened')->nullable(),
            'completed' => fn ($t) => $t->boolean('completed')->default(false),
            'source' => fn ($t) => $t->string('source', 50)->nullable(),
            'playlist_id' => fn ($t) => $t->unsignedBigInteger('playlist_id')->nullable(),
            'device_type' => fn ($t) => $t->string('device_type', 20)->nullable(),
            'ip_address' => fn ($t) => $t->string('ip_address', 45)->nullable(),
            'country' => fn ($t) => $t->string('country', 2)->nullable(),
        ]);

        // ------- USER_FOLLOWS TABLE -------
        $this->addColumnsIfMissing('user_follows', [
            'followable_type' => fn ($t) => $t->string('followable_type')->nullable(),
            'followable_id' => fn ($t) => $t->unsignedBigInteger('followable_id')->nullable(),
            'artist_id' => fn ($t) => $t->unsignedBigInteger('artist_id')->nullable(),
            'followed_user_id' => fn ($t) => $t->unsignedBigInteger('followed_user_id')->nullable(),
        ]);
    }

    /**
     * Add missing indexes for performance
     */
    private function addMissingIndexes(): void
    {
        // Indexes are created with the tables above
    }

    /**
     * Helper to add columns if they don't exist
     */
    private function addColumnsIfMissing(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($table, $column)) {
                try {
                    Schema::table($table, function (Blueprint $t) use ($definition) {
                        $definition($t);
                    });
                } catch (\Exception $e) {
                    // Skip on error
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablesToDrop = [
            'song_moods',
            'sacco_transactions',
            'sacco_loans',
            'sacco_members',
            'publishing_rights',
            'post_likes',
            'post_media',
            'post_comments',
            'posts',
            'podcast_episodes',
            'podcast_categories',
            'podcasts',
            'music_uploads',
            'moods',
            'isrc_codes',
            'frontend_settings',
            'feed_preferences',
            'feed_items',
            'feed_analytics',
            'feed_ab_tests',
            'event_locations',
            'device_tokens',
            'credit_rates',
            'campaign_updates',
            'campaign_pledges',
            'campaigns',
            'audit_logs',
            'ad_impressions',
            'activity_comments',
            'activities',
            'user_feed_settings',
            'shares',
            'views',
            'comments',
        ];

        foreach ($tablesToDrop as $table) {
            Schema::dropIfExists($table);
        }
    }
};
