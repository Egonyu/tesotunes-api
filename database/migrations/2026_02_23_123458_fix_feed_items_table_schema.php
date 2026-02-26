<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild feed_items to match FeedItem model.
     *
     * Old schema: user_id, content morph, content_source, relevance_score, engagement_score, is_seen, seen_at
     * New schema: uuid, type, module, title, body, actor_*, subject morph, media_*, engagement counts, ranking, etc.
     */
    public function up(): void
    {
        Schema::dropIfExists('feed_items');

        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 50)->index();         // song_release, user_post, event_created, etc.
            $table->string('module', 50)->index();        // music, events, store, social, etc.
            $table->string('title');
            $table->text('body')->nullable();

            // Actor (who performed the action)
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_type', 50)->nullable(); // user, artist, system
            $table->string('actor_name')->nullable();
            $table->string('actor_avatar_url')->nullable();
            $table->boolean('actor_verified')->default(false);

            // Subject (the thing the action was about — polymorphic)
            $table->nullableMorphs('subject');

            // Media
            $table->string('media_type', 20)->nullable();         // image, video, song, album
            $table->string('media_url')->nullable();
            $table->string('media_thumbnail_url')->nullable();
            $table->integer('media_duration_seconds')->nullable();

            // Engagement
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);

            // Visibility & ranking
            $table->string('visibility', 20)->default('public');
            $table->string('required_membership')->nullable();
            $table->decimal('base_rank_boost', 8, 4)->default(0);
            $table->boolean('is_prestige')->default(false);
            $table->boolean('has_celebration')->default(false);
            $table->boolean('is_aggregated')->default(false);
            $table->unsignedInteger('aggregation_count')->default(0);

            // Region & tags
            $table->string('region', 10)->nullable();
            $table->string('language', 10)->nullable();
            $table->json('tags')->nullable();
            $table->json('actions')->nullable();    // CTA buttons
            $table->json('extras')->nullable();     // flexible metadata

            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign key
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            // Composite indexes for feed queries
            $table->index(['module', 'published_at']);
            $table->index(['visibility', 'published_at']);
            $table->index(['actor_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');

        // Restore original minimal schema
        Schema::create('feed_items', function (Blueprint $table) {
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
    }
};
