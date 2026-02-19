<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Forum Categories
        if (! Schema::hasTable('forum_categories')) {
            Schema::create('forum_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('icon')->nullable();
                $table->string('color')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('topics_count')->default(0);
                $table->unsignedInteger('replies_count')->default(0);
                $table->timestamps();
            });
        }

        // Forum Topics
        if (! Schema::hasTable('forum_topics')) {
            Schema::create('forum_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('forum_categories')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('content');
                $table->boolean('is_pinned')->default(false);
                $table->boolean('is_locked')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->string('status')->default('published'); // published, draft, hidden, spam
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('reply_count')->default(0);
                $table->unsignedInteger('likes_count')->default(0);
                $table->foreignId('last_reply_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['category_id', 'status']);
                $table->index('last_activity_at');
            });
        }

        // Forum Replies
        if (! Schema::hasTable('forum_replies')) {
            Schema::create('forum_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('topic_id')->constrained('forum_topics')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('forum_replies')->nullOnDelete();
                $table->longText('content');
                $table->unsignedInteger('likes_count')->default(0);
                $table->boolean('is_solution')->default(false);
                $table->boolean('is_highlighted')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['topic_id', 'created_at']);
            });
        }

        // Polls
        if (! Schema::hasTable('polls')) {
            Schema::create('polls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->nullableMorphs('pollable'); // for attaching to topics, etc.
                $table->string('title');
                $table->text('description')->nullable();
                $table->boolean('allow_multiple_votes')->default(false);
                $table->boolean('show_results_before_vote')->default(true);
                $table->boolean('is_anonymous')->default(false);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedInteger('total_votes')->default(0);
                $table->string('status')->default('active'); // active, closed, draft
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        // Poll Options
        if (! Schema::hasTable('poll_options')) {
            Schema::create('poll_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
                $table->string('option_text');
                $table->string('image')->nullable();
                $table->unsignedInteger('vote_count')->default(0);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index('poll_id');
            });
        }

        // Poll Votes
        if (! Schema::hasTable('poll_votes')) {
            Schema::create('poll_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
                $table->foreignId('option_id')->constrained('poll_options')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('voted_at')->useCurrent();

                $table->unique(['poll_id', 'user_id', 'option_id']);
                $table->index('poll_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('forum_replies');
        Schema::dropIfExists('forum_topics');
        Schema::dropIfExists('forum_categories');
    }
};
