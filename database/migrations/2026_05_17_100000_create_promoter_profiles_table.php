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

            // One profile per user — any role can be a promoter
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Linked to a store for listing management; provisioned on first listing creation
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->string('display_name', 200);
            $table->string('slug', 220)->unique();
            $table->text('bio')->nullable();

            // Platform and content targeting
            $table->json('platforms')->nullable();          // ['tiktok', 'radio', 'club', ...]
            $table->json('niches')->nullable();             // ['afrobeats', 'gospel', 'hiphop', ...]
            $table->json('audience_regions')->nullable();   // ['Uganda', 'Kenya', ...]
            $table->text('audience_summary')->nullable();

            // Social presence
            $table->json('social_links')->nullable();       // {instagram_url, tiktok_url, ...}

            // Portfolio and proof
            $table->json('portfolio_items')->nullable();    // [{title, summary, outcome, platform, asset_url}]
            $table->json('proof_points')->nullable();       // ['10k+ TikTok followers', '2 years radio DJ', ...]
            $table->json('campaign_highlights')->nullable();

            // Service details
            $table->unsignedSmallInteger('response_time_hours')->nullable();

            // Tier: managed by admin/system, not user-editable ($fillable excludes these)
            $table->string('tier', 30)->default('starter'); // starter, verified, premium

            // Denormalized counters — updated by observers/jobs
            $table->unsignedInteger('total_listings')->default(0);
            $table->unsignedInteger('total_completed_orders')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);

            // Verification — managed by admin only
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Onboarding tracking
            $table->timestamp('onboarded_at')->nullable();

            // Lifecycle
            $table->string('status', 30)->default('active'); // active, paused, suspended

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
