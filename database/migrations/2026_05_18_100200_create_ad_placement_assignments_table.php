<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placement_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();

            // References ad_placement_configs.placement_key
            $table->string('placement_key', 60);

            // Higher priority wins when multiple ads compete for the same slot (1–10)
            $table->unsignedTinyInteger('priority')->default(5);

            // Weight for proportional random rotation among same-priority ads (1–100)
            $table->unsignedTinyInteger('weight')->default(10);

            // Assignment-level toggle (independent of the ad's own is_active)
            $table->boolean('is_active')->default(true);

            // Optional schedule that overrides the ad's own schedule for this zone
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            // An ad may only be assigned once per zone
            $table->unique(['ad_id', 'placement_key'], 'apa_ad_zone_unique');

            // Serving query: all active assignments for a given zone
            $table->index(['placement_key', 'is_active', 'priority'], 'apa_serving_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placement_assignments');
    }
};
