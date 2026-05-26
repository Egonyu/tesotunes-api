<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Identity
            $table->string('title', 255);
            $table->string('advertiser_name', 255)->nullable();

            // Ad type determines which content fields are used
            // image | html | audio | native | google_adsense
            $table->string('type', 30);

            // Dimensions hint for layout rendering
            // banner_728x90 | banner_320x50 | square_300x250 | native | audio | html
            $table->string('format', 40);

            // Image ad fields
            $table->string('image_url', 2048)->nullable();
            $table->string('click_url', 2048)->nullable();
            $table->string('cta_text', 100)->nullable();

            // HTML ad fields
            $table->text('html_content')->nullable();

            // Audio ad fields
            $table->string('audio_url', 2048)->nullable();
            $table->unsignedSmallInteger('audio_duration_seconds')->nullable();

            // Native ad fields
            $table->string('native_headline', 255)->nullable();
            $table->text('native_body')->nullable();
            $table->string('native_image_url', 2048)->nullable();

            // Google AdSense fields
            $table->string('adsense_slot_id', 50)->nullable();
            $table->string('adsense_format', 30)->nullable();

            // Scheduling
            $table->boolean('is_active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Budget (in UGX, nullable for non-paid/direct placements)
            $table->decimal('total_budget_ugx', 14, 2)->nullable();
            $table->decimal('daily_budget_ugx', 14, 2)->nullable();
            $table->decimal('cost_per_impression_ugx', 10, 4)->nullable();
            $table->decimal('cost_per_click_ugx', 10, 2)->nullable();

            // Targeting (applied at ad level; placement zones add their own constraints)
            $table->json('target_tiers')->nullable();     // ['free'] or ['free','premium']
            $table->json('target_devices')->nullable();   // ['desktop','mobile'] or null = all
            $table->json('target_countries')->nullable(); // ['UG','KE','TZ'] or null = all

            // Default priority when assigned to a zone (1 = low, 10 = high)
            $table->unsignedTinyInteger('priority')->default(5);

            // Admin notes
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Ad-serving query: active ads within schedule
            $table->index(['is_active', 'starts_at', 'ends_at'], 'ads_serving_idx');
            $table->index(['type', 'format'], 'ads_type_format_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
