<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promoter_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Nullable reference to a store; no FK constraint since the stores table
            // is not guaranteed to exist across all environments.
            $table->unsignedBigInteger('store_id')->nullable()->index();

            $table->string('display_name', 200);
            $table->string('slug', 220)->unique();
            $table->text('bio')->nullable();

            $table->json('platforms')->nullable();
            $table->json('niches')->nullable();
            $table->json('audience_regions')->nullable();
            $table->text('audience_summary')->nullable();

            $table->json('social_links')->nullable();

            $table->json('portfolio_items')->nullable();
            $table->json('proof_points')->nullable();
            $table->json('campaign_highlights')->nullable();

            $table->unsignedSmallInteger('response_time_hours')->nullable();

            $table->string('tier', 30)->default('starter');

            $table->unsignedInteger('total_listings')->default(0);
            $table->unsignedInteger('total_completed_orders')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);

            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('onboarded_at')->nullable();

            $table->string('status', 30)->default('active');

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_verified'], 'pp_status_verified_idx');
            $table->index(['tier', 'status'], 'pp_tier_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promoter_profiles');
    }
};
