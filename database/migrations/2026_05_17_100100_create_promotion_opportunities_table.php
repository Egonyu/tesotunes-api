<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_opportunities')) {
            return;
        }

        Schema::create('promotion_opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 280)->unique();

            // Who posted the opportunity
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic promotable: Song, Album, or Event
            $table->string('promotable_type', 100)->nullable();
            $table->unsignedBigInteger('promotable_id')->nullable();

            // Brief
            $table->string('title', 255);
            $table->text('brief')->nullable();

            // Targeting requirements
            $table->json('target_platforms')->nullable();        // ['tiktok', 'instagram', 'radio']
            $table->json('target_audience_niches')->nullable();  // ['afrobeats', 'gospel']
            $table->json('target_regions')->nullable();          // ['Uganda', 'East Africa']

            // Budget (artist's willingness to pay)
            $table->decimal('budget_min_ugx', 12, 2)->default(0);
            $table->decimal('budget_max_ugx', 12, 2)->default(0);
            $table->unsignedInteger('budget_credits')->default(0);

            // Timeline
            $table->timestamp('deadline_at')->nullable();

            // What the artist needs delivered
            $table->json('deliverables')->nullable();

            // Status state machine
            // draft → open → reviewing → awarded → closed | cancelled
            $table->string('status', 30)->default('open');

            // Awarded application — set when artist picks a winner
            $table->unsignedBigInteger('awarded_application_id')->nullable();
            $table->timestamp('awarded_at')->nullable();

            // Denormalized counters
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('application_count')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Polymorphic lookup
            $table->index(['promotable_type', 'promotable_id'], 'po_promotable_idx');

            // Feed queries: open opportunities by deadline
            $table->index(['status', 'deadline_at'], 'po_status_deadline_idx');

            // Artist's own opportunities
            $table->index('created_by_user_id', 'po_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_opportunities');
    }
};
