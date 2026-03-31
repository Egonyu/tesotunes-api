<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('original_filename');
            $table->string('content_type')->nullable();
            $table->string('file_extension', 32)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('part_size_bytes');
            $table->unsignedSmallInteger('total_parts');
            $table->string('disk', 64);
            $table->string('target_key', 1024);
            $table->string('chunk_prefix', 1024);
            $table->string('status', 32)->default('initiated');
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['artist_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_sessions');
    }
};
