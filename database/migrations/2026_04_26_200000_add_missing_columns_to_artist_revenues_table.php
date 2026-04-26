<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_revenues', function (Blueprint $table) {
            $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete()->after('sourceable_id');
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete()->after('song_id');
            $table->string('source_platform')->nullable()->after('notes');
            $table->unsignedInteger('transaction_count')->default(0)->after('source_platform');
        });
    }

    public function down(): void
    {
        Schema::table('artist_revenues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('song_id');
            $table->dropConstrainedForeignId('album_id');
            $table->dropColumn(['source_platform', 'transaction_count']);
        });
    }
};
