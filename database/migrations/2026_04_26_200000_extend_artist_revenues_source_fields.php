<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_revenues', function (Blueprint $table) {
            if (! Schema::hasColumn('artist_revenues', 'song_id')) {
                $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete()->after('sourceable_id');
            }
            if (! Schema::hasColumn('artist_revenues', 'album_id')) {
                $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete()->after('song_id');
            }
            if (! Schema::hasColumn('artist_revenues', 'source_platform')) {
                $table->string('source_platform')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('artist_revenues', 'transaction_count')) {
                $table->unsignedInteger('transaction_count')->default(0)->after('source_platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('artist_revenues', function (Blueprint $table) {
            if (Schema::hasColumn('artist_revenues', 'song_id')) {
                $table->dropConstrainedForeignId('song_id');
            }
            if (Schema::hasColumn('artist_revenues', 'album_id')) {
                $table->dropConstrainedForeignId('album_id');
            }
            if (Schema::hasColumn('artist_revenues', 'source_platform')) {
                $table->dropColumn('source_platform');
            }
            if (Schema::hasColumn('artist_revenues', 'transaction_count')) {
                $table->dropColumn('transaction_count');
            }
        });
    }
};
