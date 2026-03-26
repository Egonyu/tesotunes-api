<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('promotion_slug')->nullable();
            $table->string('promotion_title');
            $table->string('promotion_type')->nullable();
            $table->string('promotion_platform')->nullable();
            $table->decimal('price_credits', 12, 2)->default(0);
            $table->decimal('price_ugx', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->text('request_notes')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->string('featured_image_url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'status'], 'epr_event_status_idx');
            $table->index(['promotion_slug', 'status'], 'epr_slug_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_promotion_requests');
    }
};
