<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            // Link a promotion listing to the specific content it promotes by default.
            // Nullable — general listings have no pre-set content; curated listings do.
            $table->string('default_promotable_type', 100)->nullable()->after('metadata');
            $table->unsignedBigInteger('default_promotable_id')->nullable()->after('default_promotable_type');

            // Link back to the promoter profile that owns this listing
            $table->foreignId('promoter_profile_id')
                ->nullable()
                ->after('default_promotable_id')
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
