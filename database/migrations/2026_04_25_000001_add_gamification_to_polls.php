<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->string('poll_type')->default('general')->after('status');
            $table->string('category')->nullable()->after('poll_type');
            $table->unsignedTinyInteger('credits_reward')->default(3)->after('category');

            $table->index(['poll_type', 'status']);
            $table->index('category');
        });

        Schema::table('poll_options', function (Blueprint $table) {
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete()->after('poll_id');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete()->after('song_id');

            $table->index('song_id');
            $table->index('artist_id');
        });
    }

    public function down(): void
    {
        Schema::table('poll_options', function (Blueprint $table) {
            $table->dropForeign(['artist_id']);
            $table->dropForeign(['song_id']);
            $table->dropIndex(['artist_id']);
            $table->dropIndex(['song_id']);
            $table->dropColumn(['song_id', 'artist_id']);
        });

        Schema::table('polls', function (Blueprint $table) {
            $table->dropIndex(['poll_type', 'status']);
            $table->dropIndex(['category']);
            $table->dropColumn(['poll_type', 'category', 'credits_reward']);
        });
    }
};
