<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adaptive streaming delivery: songs carry a pointer to their HLS master
 * playlist once the transcode ladder (64/128/320 kbps) has been generated.
 * stream_url remains the progressive-download fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('hls_master_path')->nullable()->after('audio_file_128');
            $table->timestamp('hls_generated_at')->nullable()->after('hls_master_path');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn(['hls_master_path', 'hls_generated_at']);
        });
    }
};
