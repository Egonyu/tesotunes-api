<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (! Schema::hasColumn('songs', 'audio_quality_score')) {
                $table->unsignedInteger('audio_quality_score')->nullable()->after('sample_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (Schema::hasColumn('songs', 'audio_quality_score')) {
                $table->dropColumn('audio_quality_score');
            }
        });
    }
};
