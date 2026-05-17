<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            // Fixes the current bug: song_id is validated on purchase but never persisted.
            // Polymorphic so it covers Song, Album, and Event uniformly.
            $table->string('promotable_type', 100)->nullable()->after('dispute_reason');
            $table->unsignedBigInteger('promotable_id')->nullable()->after('promotable_type');

            // If this order was created via an opportunity award (not a direct marketplace purchase)
            $table->unsignedBigInteger('opportunity_id')->nullable()->after('promotable_id');
            $table->unsignedBigInteger('application_id')->nullable()->after('opportunity_id');

            $table->index(
                ['promotable_type', 'promotable_id'],
                'soi_promotable_idx'
            );
            $table->index('opportunity_id', 'soi_opportunity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropIndex('soi_promotable_idx');
            $table->dropIndex('soi_opportunity_idx');
            $table->dropColumn(['promotable_type', 'promotable_id', 'opportunity_id', 'application_id']);
        });
    }
};
