<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CMS Module - Frontend Sections
     * Allows WordPress-like control over homepage and other page sections
     */
    public function up(): void
    {
        Schema::create('frontend_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Section title (e.g., "Trending Now", "New Releases")
            $table->string('slug')->unique(); // URL-friendly identifier
            $table->string('page')->default('home')->index(); // Which page: home, discover, etc.
            
            // Section Display Type
            $table->string('type')->default('grid'); // carousel, grid, list, featured, custom
            
            // Content Configuration
            $table->string('content_type')->nullable(); // songs, albums, artists, playlists, moods, genres, slideshow
            $table->unsignedBigInteger('content_id')->nullable(); // Specific content ID (optional)
            $table->text('query')->nullable(); // JSON query for dynamic content filtering
            
            // Display Settings
            $table->integer('limit')->default(10); // How many items to show
            $table->string('order_by')->default('created_at'); // Sort field
            $table->enum('order_direction', ['asc', 'desc'])->default('desc'); // Sort direction
            $table->integer('display_order')->default(0)->index(); // Order on page (lower = higher)
            
            // Visibility
            $table->boolean('is_enabled')->default(true)->index(); // Show/hide section
            $table->boolean('show_title')->default(true); // Display section title
            $table->boolean('show_view_all')->default(true); // Show "View All" button
            
            // Styling
            $table->string('background_color')->nullable(); // Custom background color
            $table->string('text_color')->nullable(); // Custom text color
            $table->string('layout_style')->nullable(); // compact, expanded, minimal
            
            // Advanced Settings
            $table->json('settings')->nullable(); // Additional custom settings
            $table->json('filters')->nullable(); // Complex filtering rules
            $table->json('metadata')->nullable(); // SEO and additional metadata
            
            // Polymorphic relationship (optional)
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->index(['sectionable_type', 'sectionable_id']);
            
            $table->softDeletes();
            $table->timestamps();
            
            // Composite indexes for performance
            $table->index(['page', 'is_enabled', 'display_order']);
        });

        // Create section_items junction table for custom sections
        Schema::create('frontend_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('frontend_sections')->onDelete('cascade');
            $table->morphs('itemable'); // Polymorphic: songs, albums, artists, etc.
            $table->integer('display_order')->default(0);
            $table->json('metadata')->nullable(); // Custom data per item
            $table->timestamps();
            
            $table->index(['section_id', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frontend_section_items');
        Schema::dropIfExists('frontend_sections');
    }
};
