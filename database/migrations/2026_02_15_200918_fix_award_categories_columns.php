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
        Schema::table('award_categories', function (Blueprint $table) {
            // Add category_type column if it doesn't exist
            if (! Schema::hasColumn('award_categories', 'category_type')) {
                $table->string('category_type', 50)->default('general')->after('description');
            }

            // Add artwork column if it doesn't exist
            if (! Schema::hasColumn('award_categories', 'artwork')) {
                $table->string('artwork')->nullable()->after('icon');
            }
        });

        // Copy data from nominee_type to category_type if nominee_type exists
        if (Schema::hasColumn('award_categories', 'nominee_type')) {
            \DB::statement('UPDATE award_categories SET category_type = nominee_type WHERE nominee_type IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('award_categories', function (Blueprint $table) {
            if (Schema::hasColumn('award_categories', 'category_type')) {
                $table->dropColumn('category_type');
            }
            if (Schema::hasColumn('award_categories', 'artwork')) {
                $table->dropColumn('artwork');
            }
        });
    }
};
