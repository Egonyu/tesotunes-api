<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('store_products', 'default_promotable_type')) {
            return;
        }

        Schema::table('store_products', function (Blueprint $table) {
            $table->string('default_promotable_type', 100)->nullable();
            $table->unsignedBigInteger('default_promotable_id')->nullable();

            $table->foreignId('promoter_profile_id')
                ->nullable()
                ->constrained('promoter_profiles')
                ->nullOnDelete();

            $table->index(
                ['default_promotable_type', 'default_promotable_id'],
                'sp_default_promotable_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropIndex('sp_default_promotable_idx');
            $table->dropConstrainedForeignId('promoter_profile_id');
            $table->dropColumn(['default_promotable_type', 'default_promotable_id']);
        });
    }
};
