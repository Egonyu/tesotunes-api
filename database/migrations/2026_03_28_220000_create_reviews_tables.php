<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->morphs('reviewable');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedTinyInteger('rating');
                $table->string('title')->nullable();
                $table->text('content');
                $table->string('status')->default('approved');
                $table->boolean('is_verified_purchase')->default(false);
                $table->unsignedInteger('helpful_count')->default(0);
                $table->unsignedInteger('not_helpful_count')->default(0);
                $table->text('seller_response')->nullable();
                $table->timestamp('seller_response_at')->nullable();
                $table->json('metadata')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['reviewable_type', 'reviewable_id', 'status']);
                $table->index(['user_id', 'reviewable_type', 'reviewable_id']);
            });
        }

        if (! Schema::hasTable('review_helpful_votes')) {
            Schema::create('review_helpful_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_helpful')->default(true);
                $table->timestamps();

                $table->unique(['review_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_helpful_votes');
        Schema::dropIfExists('reviews');
    }
};
