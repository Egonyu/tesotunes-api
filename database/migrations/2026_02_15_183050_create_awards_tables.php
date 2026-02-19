<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Awards (seasons/shows) - only create if doesn't exist
        if (! Schema::hasTable('awards')) {
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
        }

        // Award Categories - only create if doesn't exist
        if (! Schema::hasTable('award_categories')) {
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
        }

        // Award Nominations - only create if doesn't exist
        if (! Schema::hasTable('award_nominations')) {
            Schema::create('award_nominations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('award_id')->constrained('awards')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('award_categories')->cascadeOnDelete();
                $table->string('nominee_type');
                $table->unsignedBigInteger('nominee_id')->nullable();
                $table->string('nominee_name');
                $table->string('nominee_artwork')->nullable();
                $table->unsignedBigInteger('nominated_by_id')->nullable();
                $table->text('nomination_reason')->nullable();
                $table->string('status')->default('pending');
                $table->boolean('is_official')->default(false);
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->foreign('nominated_by_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['award_id', 'category_id', 'status']);
                $table->index(['nominee_type', 'nominee_id']);
            });
        }

        // Award Votes - only create if doesn't exist
        if (! Schema::hasTable('award_votes')) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('award_votes');
        Schema::dropIfExists('award_nominations');
        Schema::dropIfExists('award_categories');
        Schema::dropIfExists('awards');
    }
};
