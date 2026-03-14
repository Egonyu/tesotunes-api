<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained('playlists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['playlist_id', 'user_id']);
        });

        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency')->default('UGX');
            $table->string('category')->nullable();
            $table->string('type')->default('physical');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_carts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('store_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('store_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 12, 2);
            $table->timestamps();
        });

        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency')->default('UGX');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->text('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        Schema::create('store_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 100)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamp('visited_at')->useCurrent()->index();
        });

        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->json('tiers');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('total_members')->default(0);
            $table->decimal('monthly_revenue', 12, 2)->default(0);
            $table->boolean('allow_monthly')->default(true);
            $table->boolean('allow_yearly')->default(true);
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('artist_id');
            $table->index('status');
        });

        Schema::create('loyalty_card_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tier', 50);
            $table->enum('subscription_type', ['monthly', 'yearly']);
            $table->decimal('price_paid', 12, 2);
            $table->string('currency', 3)->default('UGX');
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('renewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('renewal_reminder_sent')->default(false);
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_transaction_id')->nullable();
            $table->unsignedInteger('total_renewals')->default(0);
            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['loyalty_card_id', 'user_id', 'status'], 'unique_active_membership');
            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['content', 'merchandise', 'experience', 'discount', 'points']);
            $table->string('required_tier', 50);
            $table->string('content_type', 50)->nullable();
            $table->string('content_url', 500)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('experience_type', 100)->nullable();
            $table->unsignedInteger('points_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('current_redemptions')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('loyalty_card_id');
            $table->index('type');
            $table->index('required_tier');
        });

        Schema::create('loyalty_reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('loyalty_card_member_id')->constrained('loyalty_card_members')->cascadeOnDelete();
            $table->enum('status', ['pending', 'fulfilled', 'cancelled'])->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('fulfilment_notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });

        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lifetime_spent')->default(0);
            $table->decimal('current_multiplier', 3, 2)->default(1.00);
            $table->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['earned', 'spent', 'expired', 'adjusted']);
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->string('source');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('base_points')->nullable();
            $table->decimal('multiplier', 3, 2)->default(1.00);
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('type');
            $table->index('source');
            $table->index('created_at');
        });

        Schema::create('podcast_listens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained('podcast_episodes')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->integer('listen_duration')->default(0);
            $table->boolean('completed')->default(false);
            $table->string('device_type')->nullable();
            $table->string('country')->nullable();
            $table->timestamp('listened_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'episode_id']);
            $table->index(['episode_id', 'created_at']);
            $table->index('completed');
        });

        Schema::create('podcast_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('podcast_id')->constrained('podcasts')->cascadeOnDelete();
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'podcast_id']);
            $table->index('podcast_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('podcast_subscriptions');
        Schema::dropIfExists('podcast_listens');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_points');
        Schema::dropIfExists('loyalty_reward_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_card_members');
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('store_visits');
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('store_cart_items');
        Schema::dropIfExists('store_carts');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('playlist_collaborators');
    }
};
