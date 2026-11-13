<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edula Partners: a local business posts a deal to the timeline, a member
 * claims it (spending credits when the deal has a price) and shows the
 * claim's QR token at the counter, where the merchant redeems it.
 *
 * Claims hold the money trail: credits spent at claim time, the UGX the
 * merchant earned at redemption, and the platform's share, so a refund or
 * an audit never has to reconstruct a rate that has since changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('discount_label', 80)->nullable();
            $table->text('terms')->nullable();
            $table->string('image')->nullable();
            $table->string('town', 80)->nullable()->index();
            $table->unsignedInteger('credits_price')->default(0);
            $table->decimal('ugx_value', 12, 2)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->unsignedInteger('max_claims')->nullable();
            $table->unsignedInteger('claims_count')->default(0);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['status', 'valid_until', 'town']);
        });

        Schema::create('deal_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->unsignedInteger('credits_spent')->default(0);
            $table->string('status', 20)->default('claimed')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('merchant_ugx', 12, 2)->nullable();
            $table->decimal('platform_fee_ugx', 12, 2)->nullable();
            $table->decimal('credit_rate_ugx', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['deal_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_claims');
        Schema::dropIfExists('deals');
    }
};
