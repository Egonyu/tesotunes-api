<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('slug')->unique()->index(); // e.g., 'terms-of-service', 'privacy-policy'
            $table->string('title'); // e.g., 'Terms of Service'
            $table->string('subtitle')->nullable(); // e.g., 'Artist Agreement'
            $table->string('type')->index(); // 'terms', 'privacy', 'acceptable_use', 'artist_agreement', 'copyright', 'cookies', 'disclaimer'
            $table->text('description')->nullable(); // Short description for admin list
            $table->longText('content'); // Main content (HTML/Markdown)
            $table->string('status')->default('draft'); // 'draft', 'published', 'archived'
            $table->integer('version')->default(1); // Version tracking
            $table->string('applies_to')->default('all'); // 'all', 'users', 'artists', 'labels', 'event_organizers'
            $table->json('metadata')->nullable(); // Additional metadata (languages, regions, etc.)
            $table->boolean('requires_acceptance')->default(false); // If users must accept this
            $table->timestamp('effective_date')->nullable(); // When this version becomes effective
            $table->timestamp('sunset_date')->nullable(); // When this version stops being effective
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'status']);
            $table->index(['applies_to', 'status']);
        });

        // Track user acceptance of legal documents
        Schema::create('legal_page_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_page_id')->constrained()->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['user_id', 'legal_page_id', 'version']);
            $table->index(['legal_page_id', 'version']);
        });

        // Track changes/versions of legal pages
        Schema::create('legal_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_page_id')->constrained()->cascadeOnDelete();
            $table->integer('version_number');
            $table->string('title');
            $table->longText('content');
            $table->json('changes')->nullable(); // Record of what changed
            $table->string('changelog')->nullable(); // Human-readable changelog
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['legal_page_id', 'version_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_page_versions');
        Schema::dropIfExists('legal_page_acceptances');
        Schema::dropIfExists('legal_pages');
    }
};
