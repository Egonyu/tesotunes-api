<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('forum_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('forum_categories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('published');
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

        Schema::create('forum_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum_topics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('forum_replies')->nullOnDelete();
            $table->longText('content');
            $table->unsignedInteger('likes_count')->default(0);
            $table->boolean('is_solution')->default(false);
            $table->boolean('is_highlighted')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['topic_id', 'created_at']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('poll_type')->default('general');
            $table->string('category')->nullable();
            $table->string('audience')->default('all');
            $table->boolean('allow_guest_responses')->default(true);
            $table->boolean('show_results_before_completion')->default(true);
            $table->boolean('is_anonymous')->default(false);
            $table->unsignedTinyInteger('credits_reward')->default(3);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('total_responses')->default(0);
            $table->string('status')->default('draft');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['poll_type', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('category');
        });

        Schema::create('poll_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('question_text', 500);
            $table->text('description')->nullable();
            $table->string('question_type')->default('multiple_choice');
            $table->boolean('is_required')->default(true);
            $table->boolean('allow_multiple')->default(false);
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['poll_id', 'position']);
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('poll_questions')->cascadeOnDelete();
            $table->string('option_text');
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->unsignedInteger('response_count')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'position']);
        });

        Schema::create('poll_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_token', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_complete')->default(false);
            $table->timestamp('started_at')->nullable()->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['poll_id', 'is_complete']);
            $table->index('session_token');
            $table->index(['poll_id', 'user_id']);
        });

        Schema::create('poll_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('poll_responses')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('poll_questions')->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('poll_options')->nullOnDelete();
            $table->text('answer_text')->nullable();
            $table->tinyInteger('rating_value')->nullable();
            $table->unsignedSmallInteger('rank_position')->nullable();
            $table->timestamps();

            $table->index(['response_id', 'question_id']);
            $table->unique(['response_id', 'question_id', 'option_id'], 'unique_answer_per_option');
        });

        Schema::create('awards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('year');
            $table->string('season', 100)->nullable();
            $table->string('artwork')->nullable();
            $table->string('banner')->nullable();
            $table->timestamp('nomination_starts_at')->nullable();
            $table->timestamp('nomination_ends_at')->nullable();
            $table->timestamp('voting_starts_at')->nullable();
            $table->timestamp('voting_ends_at')->nullable();
            $table->timestamp('ceremony_date')->nullable();
            $table->string('status')->default('upcoming');
            $table->string('visibility')->default('public');
            $table->boolean('allow_public_nominations')->default(true);
            $table->boolean('allow_public_voting')->default(true);
            $table->integer('votes_per_category')->default(1);
            $table->timestamps();

            $table->index(['year', 'status']);
        });

        Schema::create('award_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('artwork')->nullable();
            $table->string('category_type')->default('music');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('award_nominations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('award_id')->constrained('awards')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('award_categories')->cascadeOnDelete();
            $table->string('nominee_type');
            $table->unsignedBigInteger('nominee_id')->nullable();
            $table->string('nominee_name');
            $table->string('nominee_artwork')->nullable();
            $table->foreignId('nominated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('nomination_reason')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_official')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['award_id', 'category_id', 'status']);
            $table->index(['nominee_type', 'nominee_id']);
        });

        Schema::create('award_votes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('award_id')->constrained('awards')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('award_categories')->cascadeOnDelete();
            $table->foreignId('nomination_id')->constrained('award_nominations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('weight')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['award_id', 'category_id', 'user_id'], 'unique_vote_per_category');
            $table->index(['award_id', 'nomination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('award_votes');
        Schema::dropIfExists('award_nominations');
        Schema::dropIfExists('award_categories');
        Schema::dropIfExists('awards');
        Schema::dropIfExists('poll_answers');
        Schema::dropIfExists('poll_responses');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('poll_questions');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('forum_replies');
        Schema::dropIfExists('forum_topics');
        Schema::dropIfExists('forum_categories');
    }
};
