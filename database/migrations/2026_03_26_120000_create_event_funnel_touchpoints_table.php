<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_funnel_touchpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 40);
            $table->string('session_key', 120);
            $table->string('source_label', 160)->nullable();
            $table->string('source', 120)->nullable();
            $table->string('channel', 120)->nullable();
            $table->string('campaign_code', 160)->nullable();
            $table->string('referral_code', 160)->nullable();
            $table->string('promoter_code', 160)->nullable();
            $table->string('touch_date', 10);
            $table->string('landing_page', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['event_id', 'stage', 'session_key', 'source_label', 'touch_date'],
                'event_funnel_touchpoints_unique_stage_touch'
            );
            $table->index(['event_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_funnel_touchpoints');
    }
};
