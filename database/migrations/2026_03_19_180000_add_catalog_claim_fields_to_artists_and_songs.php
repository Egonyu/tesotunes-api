<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->boolean('is_placeholder')->default(false)->after('is_featured');
            $table->string('claim_status')->default('unclaimed')->after('is_placeholder');
            $table->foreignId('claimed_user_id')->nullable()->after('claim_status')->constrained('users')->nullOnDelete();
            $table->foreignId('catalog_manager_user_id')->nullable()->after('claimed_user_id')->constrained('users')->nullOnDelete();

            $table->index(['is_placeholder', 'claim_status']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('status');
            $table->unsignedBigInteger('source_submission_item_id')->nullable()->after('source_type');
            $table->boolean('is_claimable')->default(false)->after('source_submission_item_id');

            $table->index(['source_type', 'source_submission_item_id'], 'songs_source_reference_index');
            $table->index(['is_claimable', 'artist_id'], 'songs_claimable_artist_index');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex('songs_source_reference_index');
            $table->dropIndex('songs_claimable_artist_index');
            $table->dropColumn([
                'source_type',
                'source_submission_item_id',
                'is_claimable',
            ]);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropForeign(['claimed_user_id']);
            $table->dropForeign(['catalog_manager_user_id']);
            $table->dropIndex(['is_placeholder', 'claim_status']);
            $table->dropColumn([
                'is_placeholder',
                'claim_status',
                'claimed_user_id',
                'catalog_manager_user_id',
            ]);
        });
    }
};
