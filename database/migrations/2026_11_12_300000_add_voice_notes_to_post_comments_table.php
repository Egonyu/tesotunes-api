<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice replies on Edula post discussions. Many members would rather speak
 * than type; a reply may carry a short recording instead of, or alongside,
 * text. The length limit is the public setting general_edula_voice_reply_seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->string('audio_path', 500)->nullable()->after('content');
            $table->unsignedSmallInteger('audio_duration_seconds')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropColumn(['audio_path', 'audio_duration_seconds']);
        });
    }
};
