<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing columns to songs table required by ArtistApiController::storeSong()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
        });
        
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'file_format')) {
                $table->string('file_format', 10)->nullable()->after('audio_file_128');
            }
        });
        
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->nullable()->after('file_format');
            }
        });
        
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'visibility')) {
                $table->string('visibility', 20)->default('public')->after('status');
            }
        });
        
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'is_streamable')) {
                $table->boolean('is_streamable')->default(true)->after('is_downloadable');
            }
        });
        
        Schema::table('songs', function (Blueprint $table) {
            if (!Schema::hasColumn('songs', 'processing_status')) {
                $table->json('processing_status')->nullable()->after('is_streamable');
            }
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $cols = ['user_id', 'file_format', 'file_size_bytes', 'visibility', 'is_streamable', 'processing_status'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('songs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
