<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-song artist opt-in that lets a song's lyrics enter the contribution task
 * pool. Rights stay clean: lyrics are only translatable once the owning artist
 * explicitly opts the song in, and they can withdraw it. One record per song.
 * See docs/architecture/ATESO_DATA_PIPELINE.md (9.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('song_lyric_optins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('song_id')->unique()->constrained('songs')->cascadeOnDelete();
            $table->foreignId('opted_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Direction of the generated translation tasks. Source is the lyric
            // language (usually Ateso for Teso music); target is the other side.
            $table->string('source_lang', 8)->default('teo');
            $table->string('target_lang', 8)->default('en');
            $table->string('region', 8)->default('ug');

            // active | withdrawn
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('tasks_generated')->default(0);

            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_lyric_optins');
    }
};
