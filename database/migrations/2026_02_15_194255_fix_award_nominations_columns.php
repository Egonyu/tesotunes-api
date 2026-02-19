<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('award_nominations', function (Blueprint $table) {
            // Add award_id column if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'award_id')) {
                $table->foreignId('award_id')->nullable()->after('uuid')->constrained('awards')->cascadeOnDelete();
            }

            // Add category_id column if it doesn't exist (alias for award_category_id)
            if (! Schema::hasColumn('award_nominations', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('award_id')->constrained('award_categories')->cascadeOnDelete();
            }

            // Add nominee columns if they don't exist
            if (! Schema::hasColumn('award_nominations', 'nominee_name')) {
                $table->string('nominee_name')->nullable()->after('nominee_id');
            }
            if (! Schema::hasColumn('award_nominations', 'nominee_artwork')) {
                $table->string('nominee_artwork')->nullable()->after('nominee_name');
            }
            if (! Schema::hasColumn('award_nominations', 'nominated_by_id')) {
                $table->foreignId('nominated_by_id')->nullable()->after('nominee_artwork')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('award_nominations', 'nomination_reason')) {
                $table->text('nomination_reason')->nullable()->after('nominated_by_id');
            }
            if (! Schema::hasColumn('award_nominations', 'is_official')) {
                $table->boolean('is_official')->default(false)->after('status');
            }
        });

        // Copy data from award_category_id to category_id if both exist
        if (Schema::hasColumn('award_nominations', 'award_category_id') && Schema::hasColumn('award_nominations', 'category_id')) {
            \DB::statement('UPDATE award_nominations SET category_id = award_category_id WHERE category_id IS NULL AND award_category_id IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('award_nominations', function (Blueprint $table) {
            // Remove added columns
            $columnsToRemove = ['award_id', 'category_id', 'nominee_name', 'nominee_artwork', 'nominated_by_id', 'nomination_reason', 'is_official'];
            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('award_nominations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
