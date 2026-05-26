<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_impressions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();

            // Which zone served this impression
            $table->string('placement_key', 60)->nullable();

            // User (nullable — anonymous visitors can see ads too)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Request context
            $table->string('ip_address', 45)->nullable();  // supports IPv6
            $table->text('user_agent')->nullable();
            $table->string('device_type', 20)->nullable(); // desktop | mobile | tablet
            $table->string('page_url', 2048)->nullable();

            // Click tracking (updated in-place on click event)
            $table->boolean('clicked')->default(false);
            $table->timestamp('clicked_at')->nullable();

            // Impressions are append-only; only created_at needed
            $table->timestamp('created_at')->useCurrent();

            // Analytics queries: impressions per ad over time
            $table->index(['ad_id', 'created_at'], 'ai_ad_time_idx');

            // Analytics queries: impressions per zone over time
            $table->index(['placement_key', 'created_at'], 'ai_zone_time_idx');

            // Frequency capping: how many impressions has this user seen today?
            $table->index(['user_id', 'placement_key', 'created_at'], 'ai_freq_cap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_impressions');
    }
};
