<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('store_order_items', 'promotable_type')) {
            return;
        }

        Schema::table('store_order_items', function (Blueprint $table) {
            $table->string('promotable_type', 100)->nullable();
            $table->unsignedBigInteger('promotable_id')->nullable();
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();

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
