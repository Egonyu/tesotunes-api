<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CMS & FRONTEND MODULE - 6 Normalized Tables
     * 
     * Principles Applied:
     * - Pages separate from blocks/widgets
     * - Media library properly structured
     * - Menu system normalized
     * - SEO metadata in dedicated table
     * - Standardized artwork/image fields
     */
    public function up(): void
    {
        // ==========================================
        // 1. CMS PAGES
        // ==========================================
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            
            // Featured image - Standardized
            $table->string('artwork', 255)->nullable();
            
            // Page type
            $table->enum('page_type', ['standard', 'landing', 'help', 'legal', 'about', 'custom'])->default('standard');
            $table->string('template', 100)->nullable(); // Template file to use
            
            // Hierarchy
            $table->foreignId('parent_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            
            // Author
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            
            // Status
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->dateTime('published_at')->nullable();
            
            // Visibility
            $table->enum('visibility', ['public', 'members_only', 'premium_only'])->default('public');
            $table->boolean('show_in_menu')->default(false);
            
            // Engagement
            $table->unsignedInteger('views_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'published_at']);
            $table->index(['slug', 'status']);
            $table->index(['parent_id', 'order']);
        });

        // ==========================================
        // 2. CMS BLOCKS/WIDGETS
        // ==========================================
        Schema::create('cms_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->string('name', 150);
            $table->string('identifier', 100)->unique(); // Used in code
            $table->text('description')->nullable();
            
            // Block type
            $table->enum('block_type', [
                'text',
                'html',
                'hero',
                'featured_content',
                'stats',
                'testimonial',
                'cta',
                'newsletter'
            ])->default('html');
            
            // Content
            $table->longText('content')->nullable();
            $table->json('settings')->nullable(); // Custom settings per block type
            
            // Media
            $table->string('artwork', 255)->nullable();
            
            // Placement
            $table->enum('placement', ['header', 'footer', 'sidebar', 'inline', 'modal'])->default('inline');
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            
            $table->timestamps();
            
            $table->index(['placement', 'is_active', 'sort_order']);
            $table->index('identifier');
        });

        // ==========================================
        // 3. NAVIGATION MENUS
        // ==========================================
        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->string('name', 100);
            $table->string('identifier', 50)->unique(); // e.g., 'main-menu', 'footer-menu'
            $table->text('description')->nullable();
            
            $table->enum('location', ['header', 'footer', 'mobile', 'sidebar', 'custom'])->default('header');
            
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['location', 'is_active']);
            $table->index('identifier');
        });

        // ==========================================
        // 4. MENU ITEMS
        // ==========================================
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            
            // Item details
            $table->string('label', 100);
            $table->string('url', 500)->nullable();
            $table->string('route_name', 100)->nullable(); // Named route
            $table->json('route_params')->nullable();
            
            // Link to page
            $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            
            // Icon/Badge
            $table->string('icon', 100)->nullable();
            $table->string('badge_text', 20)->nullable();
            $table->string('badge_color', 20)->nullable();
            
            // Display
            $table->enum('target', ['_self', '_blank'])->default('_self');
            $table->string('css_class', 100)->nullable();
            
            // Visibility
            $table->json('visible_to_roles')->nullable(); // ['guest', 'user', 'artist', 'admin']
            $table->boolean('is_active')->default(true);
            
            // Ordering
            $table->unsignedSmallInteger('sort_order')->default(0);
            
            $table->timestamps();
            
            $table->index(['menu_id', 'parent_id', 'sort_order']);
            $table->index(['is_active', 'sort_order']);
        });

        // ==========================================
        // 5. MEDIA LIBRARY
        // ==========================================
        Schema::create('media_library', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Uploader
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            
            // File details
            $table->string('filename', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size'); // bytes
            $table->string('disk', 50)->default('public'); // Storage disk
            $table->string('path', 500);
            $table->string('url', 500);
            
            // Media type
            $table->enum('media_type', ['image', 'video', 'audio', 'document', 'other'])->default('image');
            
            // Image specific
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            
            // Metadata
            $table->string('alt_text', 255)->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // EXIF, etc.
            
            // Organization
            $table->string('folder', 200)->default('/');
            $table->json('tags')->nullable();
            
            // Usage tracking
            $table->unsignedInteger('usage_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['uploaded_by_id', 'created_at']);
            $table->index(['media_type', 'created_at']);
            $table->index('folder');
            $table->index('uuid');
        });

        // ==========================================
        // 6. SEO METADATA
        // ==========================================
        Schema::create('seo_metadata', function (Blueprint $table) {
            $table->id();
            
            // Applies to any model
            $table->morphs('seoable'); // seoable_id, seoable_type
            
            // Basic SEO
            $table->string('meta_title', 200)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords', 500)->nullable();
            
            // Open Graph
            $table->string('og_title', 200)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 500)->nullable();
            $table->enum('og_type', ['website', 'article', 'music.song', 'music.album', 'product'])->default('website');
            
            // Twitter Card
            $table->enum('twitter_card', ['summary', 'summary_large_image', 'player'])->default('summary_large_image');
            $table->string('twitter_title', 200)->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image', 500)->nullable();
            
            // Schema.org
            $table->json('schema_markup')->nullable();
            
            // Indexing
            $table->boolean('no_index')->default(false);
            $table->boolean('no_follow')->default(false);
            $table->string('canonical_url', 500)->nullable();
            
            $table->timestamps();
            
            $table->unique(['seoable_type', 'seoable_id']);
            $table->index(['no_index', 'no_follow']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('media_library');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('navigation_menus');
        Schema::dropIfExists('cms_blocks');
        Schema::dropIfExists('cms_pages');
    }
};
