<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('artwork', 255)->nullable();
            $table->enum('page_type', ['standard', 'landing', 'help', 'legal', 'about', 'custom'])->default('standard');
            $table->string('template', 100)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->enum('visibility', ['public', 'members_only', 'premium_only'])->default('public');
            $table->boolean('show_in_menu')->default(false);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['slug', 'status']);
            $table->index(['parent_id', 'order']);
        });

        Schema::create('cms_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('identifier', 100)->unique();
            $table->text('description')->nullable();
            $table->enum('block_type', [
                'text',
                'html',
                'hero',
                'featured_content',
                'stats',
                'testimonial',
                'cta',
                'newsletter',
            ])->default('html');
            $table->longText('content')->nullable();
            $table->json('settings')->nullable();
            $table->string('artwork', 255)->nullable();
            $table->enum('placement', ['header', 'footer', 'sidebar', 'inline', 'modal'])->default('inline');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['placement', 'is_active', 'sort_order']);
            $table->index('identifier');
        });

        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('identifier', 50)->unique();
            $table->text('description')->nullable();
            $table->enum('location', ['header', 'footer', 'mobile', 'sidebar', 'custom'])->default('header');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['location', 'is_active']);
            $table->index('identifier');
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('url', 500)->nullable();
            $table->string('route_name', 100)->nullable();
            $table->json('route_params')->nullable();
            $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            $table->string('icon', 100)->nullable();
            $table->string('badge_text', 20)->nullable();
            $table->string('badge_color', 20)->nullable();
            $table->enum('target', ['_self', '_blank'])->default('_self');
            $table->string('css_class', 100)->nullable();
            $table->json('visible_to_roles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'sort_order']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('media_library', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->string('url', 500);
            $table->enum('media_type', ['image', 'video', 'audio', 'document', 'other'])->default('image');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('folder', 200)->default('/');
            $table->json('tags')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['uploaded_by_id', 'created_at']);
            $table->index(['media_type', 'created_at']);
            $table->index('folder');
            $table->index('uuid');
        });

        Schema::create('seo_metadata', function (Blueprint $table) {
            $table->id();
            $table->morphs('seoable');
            $table->string('meta_title', 200)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->string('og_title', 200)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 500)->nullable();
            $table->enum('og_type', ['website', 'article', 'music.song', 'music.album', 'product'])->default('website');
            $table->enum('twitter_card', ['summary', 'summary_large_image', 'player'])->default('summary_large_image');
            $table->string('twitter_title', 200)->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image', 500)->nullable();
            $table->json('schema_markup')->nullable();
            $table->boolean('no_index')->default(false);
            $table->boolean('no_follow')->default(false);
            $table->string('canonical_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id']);
            $table->index(['no_index', 'no_follow']);
        });

        Schema::create('frontend_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('page')->default('home')->index();
            $table->string('type')->default('grid');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->text('query')->nullable();
            $table->integer('limit')->default(10);
            $table->string('order_by')->default('created_at');
            $table->enum('order_direction', ['asc', 'desc'])->default('desc');
            $table->integer('display_order')->default(0)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('show_title')->default(true);
            $table->boolean('show_view_all')->default(true);
            $table->string('background_color')->nullable();
            $table->string('text_color')->nullable();
            $table->string('layout_style')->nullable();
            $table->json('settings')->nullable();
            $table->json('filters')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['sectionable_type', 'sectionable_id']);
            $table->index(['page', 'is_enabled', 'display_order']);
        });

        Schema::create('frontend_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('frontend_sections')->cascadeOnDelete();
            $table->morphs('itemable');
            $table->integer('display_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['section_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frontend_section_items');
        Schema::dropIfExists('frontend_sections');
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('media_library');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('navigation_menus');
        Schema::dropIfExists('cms_blocks');
        Schema::dropIfExists('cms_pages');
    }
};
