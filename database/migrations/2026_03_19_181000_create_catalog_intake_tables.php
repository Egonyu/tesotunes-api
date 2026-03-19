<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploader_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('processing');
            $table->string('source_name')->nullable();
            $table->string('csv_original_name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['uploader_user_id', 'status'], 'catalog_submissions_user_status_idx');
        });

        Schema::create('catalog_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_submission_id')->constrained('catalog_submissions')->cascadeOnDelete();
            $table->string('artist_name');
            $table->string('song_title');
            $table->string('audio_filename')->nullable();
            $table->string('cover_filename')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('genre')->nullable();
            $table->date('release_date')->nullable();
            $table->text('featured_artists')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');
            $table->json('validation_errors')->nullable();
            $table->json('row_payload')->nullable();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->timestamps();

            $table->index(['catalog_submission_id', 'status'], 'catalog_submission_items_status_idx');
            $table->unique(['catalog_submission_id', 'audio_filename'], 'catalog_submission_items_audio_unique');
        });

        Schema::create('catalog_claim_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claimant_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->json('requested_song_ids')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('message');
            $table->json('evidence')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['artist_id', 'status'], 'catalog_claim_artist_status_idx');
            $table->index(['claimant_user_id', 'status'], 'catalog_claim_claimant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_claim_requests');
        Schema::dropIfExists('catalog_submission_items');
        Schema::dropIfExists('catalog_submissions');
    }
};
