<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
            $table->index(['model_type', 'model_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_text')->nullable();
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('icon')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('priority')->nullable();
            $table->json('channels')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('like_count')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('properties')->nullable();
            $table->string('description')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
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
        });

        Schema::create('campaigns', function (Blueprint $table) {
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

        Schema::create('campaign_pledges', function (Blueprint $table) {
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

        Schema::create('campaign_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('image')->nullable();
            $table->boolean('backers_only')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
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

        Schema::create('feed_ab_tests', function (Blueprint $table) {
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

        Schema::create('feed_analytics', function (Blueprint $table) {
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

        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 50)->index();
            $table->string('module', 50)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 50)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_avatar_url')->nullable();
            $table->boolean('actor_verified')->default(false);
            $table->nullableMorphs('subject');
            $table->string('media_type', 20)->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_thumbnail_url')->nullable();
            $table->integer('media_duration_seconds')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->string('visibility', 20)->default('public');
            $table->string('required_membership')->nullable();
            $table->decimal('base_rank_boost', 8, 4)->default(0);
            $table->boolean('is_prestige')->default(false);
            $table->boolean('has_celebration')->default(false);
            $table->boolean('is_aggregated')->default(false);
            $table->unsignedInteger('aggregation_count')->default(0);
            $table->string('region', 10)->nullable();
            $table->string('language', 10)->nullable();
            $table->json('tags')->nullable();
            $table->json('actions')->nullable();
            $table->json('extras')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'published_at']);
            $table->index(['visibility', 'published_at']);
            $table->index(['actor_id', 'published_at']);
        });

        Schema::create('feed_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('genre_weights')->nullable();
            $table->json('artist_weights')->nullable();
            $table->json('content_type_weights')->nullable();
            $table->json('blocked_content_ids')->nullable();
            $table->json('muted_artist_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('frontend_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('isrc_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('country_code', 2)->default('UG');
            $table->string('registrant_code', 5);
            $table->string('year_code', 2);
            $table->string('designation_code', 5);
            $table->string('status', 30)->default('active');
            $table->string('registration_authority')->nullable();
            $table->string('registration_reference')->nullable();
            $table->boolean('cleared_for_distribution')->default(false);
            $table->timestamp('distribution_cleared_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'cleared_for_distribution'], 'isrc_dist_idx');
        });

        Schema::create('moods', function (Blueprint $table) {
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

        Schema::create('music_uploads', function (Blueprint $table) {
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

        Schema::create('podcast_categories', function (Blueprint $table) {
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

        Schema::create('podcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('podcast_category_id')->nullable()->constrained('podcast_categories')->nullOnDelete();
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
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('podcast_episodes', function (Blueprint $table) {
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
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table) {
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

        Schema::create('post_comments', function (Blueprint $table) {
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

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->integer('order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction_type')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('publishing_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('song_moods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mood_id')->constrained('moods')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['song_id', 'mood_id']);
        });

        Schema::create('user_feed_settings', function (Blueprint $table) {
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

        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('shareable');
            $table->string('platform', 50)->nullable();
            $table->text('message')->nullable();
            $table->string('share_link')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('viewable');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamps();
            $table->index(['viewable_type', 'viewable_id', 'created_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('content');
            $table->integer('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->string('status', 20)->default('approved');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('parent_id')->references('id')->on('comments')->nullOnDelete();
        });

        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('endpoint', 255)->index();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('response_time_ms');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('requested_at')->useCurrent()->index();
            $table->index(['endpoint', 'requested_at']);
            $table->index(['user_id', 'requested_at']);
            $table->index(['status_code', 'requested_at']);
        });

        Schema::create('api_usage_hourly', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->date('date')->index();
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('client_error_count')->default(0);
            $table->unsignedInteger('server_error_count')->default(0);
            $table->unsignedInteger('avg_response_ms')->default(0);
            $table->unsignedInteger('max_response_ms')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->unique(['endpoint', 'method', 'date', 'hour'], 'api_usage_hourly_unique');
            $table->index(['date', 'hour']);
        });

        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained('songs')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('platform_code', 50)->index();
            $table->string('platform_name', 100);
            $table->string('status', 30)->default('pending')->index();
            $table->string('platform_url')->nullable();
            $table->string('platform_id')->nullable();
            $table->json('platform_metadata')->nullable();
            $table->json('distribution_metadata')->nullable();
            $table->timestamp('live_date')->nullable();
            $table->timestamp('removed_date')->nullable();
            $table->string('removal_reason')->nullable();
            $table->timestamp('removal_requested_at')->nullable();
            $table->text('error_message')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('total_streams')->default(0);
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->timestamp('last_synced')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['song_id', 'status']);
            $table->index(['artist_id', 'status']);
            $table->unique(['song_id', 'platform_code'], 'distributions_song_platform_unique');
        });

        Schema::create('distribution_revenue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('distributions')->cascadeOnDelete();
            $table->string('reporting_period', 20);
            $table->unsignedBigInteger('streams')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
            $table->unique(['distribution_id', 'reporting_period'], 'dist_rev_period_unique');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'distribution_revenue',
            'distributions',
            'api_usage_hourly',
            'api_usage_logs',
            'comments',
            'views',
            'shares',
            'user_feed_settings',
            'song_moods',
            'settings',
            'publishing_rights',
            'post_likes',
            'post_media',
            'post_comments',
            'posts',
            'podcast_episodes',
            'podcasts',
            'podcast_categories',
            'music_uploads',
            'moods',
            'isrc_codes',
            'frontend_settings',
            'feed_preferences',
            'feed_items',
            'feed_analytics',
            'feed_ab_tests',
            'device_tokens',
            'campaign_updates',
            'campaign_pledges',
            'campaigns',
            'audit_logs',
            'activities',
            'notifications',
            'media',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
