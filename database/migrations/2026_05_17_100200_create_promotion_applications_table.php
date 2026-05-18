<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_applications')) {
            return;
        }

        Schema::create('promotion_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('opportunity_id')->constrained('promotion_opportunities')->cascadeOnDelete();
            $table->foreignId('promoter_profile_id')->constrained('promoter_profiles')->cascadeOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();

            // Promoter's offer
            $table->decimal('proposed_price_ugx', 12, 2)->default(0);
            $table->unsignedInteger('proposed_price_credits')->default(0);
            $table->text('pitch_message')->nullable();
            $table->json('proposed_deliverables')->nullable();
            $table->unsignedSmallInteger('proposed_timeline_days')->nullable();

            // Status: submitted → shortlisted → awarded | rejected | withdrawn
            $table->string('status', 30)->default('submitted');

            // Artist's feedback when rejecting or awarding
            $table->text('artist_response')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // If awarded, the resulting order
            $table->unsignedBigInteger('order_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One application per promoter per opportunity
            $table->unique(['opportunity_id', 'promoter_profile_id'], 'pa_opp_promoter_unique');

            // Artist reviewing their applications
            $table->index(['opportunity_id', 'status'], 'pa_opp_status_idx');

            // Promoter viewing their own applications
            $table->index(['applicant_user_id', 'status'], 'pa_applicant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_applications');
    }
};
