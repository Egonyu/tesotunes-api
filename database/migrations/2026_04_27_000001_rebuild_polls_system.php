<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old tables in dependency order
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');

        // Also drop the gamification columns migration artifact — it no longer applies
        // (handled by dropping and recreating the tables above)

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            // 'general' | 'song_battle' | 'artist_contest' | 'research_survey'
            $table->string('poll_type')->default('general');

            // Teso-region community categories
            $table->string('category')->nullable();

            // Targeting
            $table->string('audience')->default('all'); // 'all' | 'users' | 'artists'
            $table->boolean('allow_guest_responses')->default(true);

            // Display settings
            $table->boolean('show_results_before_completion')->default(true);
            $table->boolean('is_anonymous')->default(false);

            // Gamification — only applies to community poll types
            $table->unsignedTinyInteger('credits_reward')->default(3);

            // Scheduling
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Cached response count
            $table->unsignedInteger('total_responses')->default(0);

            // 'draft' | 'active' | 'closed' | 'archived'
            $table->string('status')->default('draft');

            // Flexible future config (e.g. max_responses_per_ip, require_completion)
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

            // 'multiple_choice' | 'free_text' | 'rating' | 'likert' | 'ranking'
            $table->string('question_type')->default('multiple_choice');

            $table->boolean('is_required')->default(true);

            // Applies to multiple_choice — allow selecting more than one option
            $table->boolean('allow_multiple')->default(false);

            // Song/artist linkage for song_battle and artist_contest polls
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();

            // Type-specific config:
            //   rating/likert: { scale_min, scale_max, min_label, max_label }
            //   multiple_choice: { shuffle_options }
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

            // Typed options for community poll types
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();

            // Cached selection count — avoids COUNT(*) on every render
            $table->unsignedInteger('response_count')->default(0);

            $table->timestamps();

            $table->index(['question_id', 'position']);
        });

        Schema::create('poll_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();

            // null = guest respondent
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Guest fingerprint — 64-char random token stored in a cookie
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

            // Populated for multiple_choice / ranking answers
            $table->foreignId('option_id')->nullable()->constrained('poll_options')->nullOnDelete();

            // Populated for free_text answers
            $table->text('answer_text')->nullable();

            // Populated for rating / likert answers
            $table->tinyInteger('rating_value')->nullable();

            // Position for ranking answers (1 = top pick)
            $table->unsignedSmallInteger('rank_position')->nullable();

            $table->timestamps();

            $table->index(['response_id', 'question_id']);
            $table->unique(['response_id', 'question_id', 'option_id'], 'unique_answer_per_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_answers');
        Schema::dropIfExists('poll_responses');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('poll_questions');
        Schema::dropIfExists('polls');

        // Restore original polls schema
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->nullableMorphs('pollable');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('allow_multiple_votes')->default(false);
            $table->boolean('show_results_before_vote')->default(true);
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('total_votes')->default(0);
            $table->string('status')->default('active');
            $table->string('poll_type')->default('general');
            $table->string('category')->nullable();
            $table->unsignedTinyInteger('credits_reward')->default(3);
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->string('option_text');
            $table->string('image')->nullable();
            $table->unsignedInteger('vote_count')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->timestamps();

            $table->index('poll_id');
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('voted_at')->useCurrent();

            $table->unique(['poll_id', 'user_id', 'option_id']);
            $table->index('poll_id');
        });
    }
};
