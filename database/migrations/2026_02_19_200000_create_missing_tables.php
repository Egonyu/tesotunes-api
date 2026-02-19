<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates tables that existed in dev but were missing from migrations.
     */
    public function up(): void
    {
        // Song-Genre many-to-many pivot table
        if (! Schema::hasTable('song_genres')) {
            Schema::create('song_genres', function (Blueprint $table) {
                $table->id();
                $table->foreignId('song_id')->constrained()->cascadeOnDelete();
                $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->unique(['song_id', 'genre_id']);
            });
        }

        // User settings table
        if (! Schema::hasTable('user_settings')) {
            Schema::create('user_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('email_notifications')->default(true);
                $table->boolean('push_notifications')->default(true);
                $table->string('language')->default('en');
                $table->string('theme')->default('system');
                $table->boolean('autoplay')->default(true);
                $table->string('audio_quality')->default('high');
                $table->boolean('explicit_content')->default(false);
                $table->boolean('show_listening_activity')->default(true);
                $table->boolean('private_profile')->default(false);
                $table->timestamps();
            });
        }

        // Failed jobs table (Laravel built-in)
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        // Playlist-Song many-to-many pivot table
        if (! Schema::hasTable('playlist_songs')) {
            Schema::create('playlist_songs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('song_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('added_by')->nullable();
                $table->integer('position')->default(0);
                $table->timestamp('added_at')->nullable();
                $table->timestamps();
                $table->unique(['playlist_id', 'song_id']);
                $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Playlist collaborators table
        if (! Schema::hasTable('playlist_collaborators')) {
            Schema::create('playlist_collaborators', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->timestamps();
                $table->unique(['playlist_id', 'user_id']);
            });
        }

        // Store products table
        if (! Schema::hasTable('store_products')) {
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
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Store carts table
        if (! Schema::hasTable('store_carts')) {
            Schema::create('store_carts', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        // Store cart items table
        if (! Schema::hasTable('store_cart_items')) {
            Schema::create('store_cart_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained('store_carts')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
                $table->integer('quantity')->default(1);
                $table->decimal('price', 12, 2);
                $table->timestamps();
            });
        }

        // Store orders table
        if (! Schema::hasTable('store_orders')) {
            Schema::create('store_orders', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('order_number')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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
        }

        // Store order items table
        if (! Schema::hasTable('store_order_items')) {
            Schema::create('store_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('store_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
                $table->integer('quantity')->default(1);
                $table->decimal('price', 12, 2);
                $table->decimal('total', 12, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('store_cart_items');
        Schema::dropIfExists('store_carts');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('playlist_collaborators');
        Schema::dropIfExists('playlist_songs');
        Schema::dropIfExists('song_genres');
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('failed_jobs');
    }
};
