<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-promoter opportunities: an artist can award the same song/album/event
 * brief to several promoters. Each award produces its own escrow order; the
 * opportunity closes when all slots are filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_opportunities', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_awards')->default(1)->after('budget_credits');
            $table->unsignedSmallInteger('awarded_count')->default(0)->after('max_awards');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_opportunities', function (Blueprint $table) {
            $table->dropColumn(['max_awards', 'awarded_count']);
        });
    }
};
