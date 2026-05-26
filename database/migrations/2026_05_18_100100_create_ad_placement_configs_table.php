<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placement_configs', function (Blueprint $table) {
            $table->id();

            // Canonical zone key — shared contract between backend and frontend
            // e.g. 'web_top_banner', 'mobile_home_in_feed', 'web_between_songs'
            $table->string('placement_key', 60)->unique();

            // Human-readable label and description for the admin panel
            $table->string('label', 150);
            $table->text('description')->nullable();

            // Which device surface this zone lives on: all | desktop | mobile
            $table->string('device_type', 20)->default('all');

            // Which ad formats are permitted in this zone
            // e.g. ['image', 'html', 'native']
            $table->json('allowed_formats');

            // Expected pixel dimensions (null = flexible / responsive)
            $table->unsignedSmallInteger('dimensions_width')->nullable();
            $table->unsignedSmallInteger('dimensions_height')->nullable();

            // Master kill-switch for the entire zone
            $table->boolean('is_enabled')->default(true);

            // Which subscriber tiers see ads in this zone
            // ['free'] means only free users see ads here
            // null means all tiers (rare — usually you spare premium users)
            $table->json('target_tiers')->nullable();

            // How many times the same user can see an ad from this zone per day
            $table->unsignedTinyInteger('frequency_cap_per_day')->default(5);

            // Max simultaneous ads rendered on a single page load for this zone
            $table->unsignedTinyInteger('max_ads_per_page')->default(1);

            // Admin context: where exactly in the UI this zone sits
            $table->text('notes')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placement_configs');
    }
};
