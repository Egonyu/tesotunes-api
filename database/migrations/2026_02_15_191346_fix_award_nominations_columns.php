<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('award_nominations', function (Blueprint $table) {
            // Add award_id if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'award_id')) {
                $table->unsignedBigInteger('award_id')->nullable()->after('uuid');
            }

            // Add category_id if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('award_id');
            }

            // Add nominee_name if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'nominee_name')) {
                $table->string('nominee_name')->nullable()->after('nominee_id');
            }

            // Add nominee_artwork if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'nominee_artwork')) {
                $table->string('nominee_artwork')->nullable()->after('nominee_name');
            }

            // Add nominated_by_id if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'nominated_by_id')) {
                $table->unsignedBigInteger('nominated_by_id')->nullable()->after('nominee_artwork');
            }

            // Add nomination_reason if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'nomination_reason')) {
                $table->text('nomination_reason')->nullable()->after('nominated_by_id');
            }

            // Add is_official if it doesn't exist
            if (! Schema::hasColumn('award_nominations', 'is_official')) {
                $table->boolean('is_official')->default(false)->after('status');
            }
        });

        // Update award_category_id to category_id if data exists
        if (Schema::hasColumn('award_nominations', 'award_category_id')) {
            // Copy data from award_category_id to category_id
            DB::table('award_nominations')->whereNotNull('award_category_id')->update([
                'category_id' => DB::raw('award_category_id'),
            ]);
        }

        // Fix award_votes table
        Schema::table('award_votes', function (Blueprint $table) {
            // Add award_id if it doesn't exist
            if (! Schema::hasColumn('award_votes', 'award_id')) {
                $table->unsignedBigInteger('award_id')->nullable()->after('uuid');
            }

            // Add category_id if it doesn't exist
            if (! Schema::hasColumn('award_votes', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('award_id');
            }

            // Add nomination_id if it doesn't exist
            if (! Schema::hasColumn('award_votes', 'nomination_id')) {
                $table->unsignedBigInteger('nomination_id')->nullable()->after('category_id');
            }

            // Add weight if it doesn't exist
            if (! Schema::hasColumn('award_votes', 'weight')) {
                $table->integer('weight')->default(1)->after('user_id');
            }

            // Add ip_address if it doesn't exist
            if (! Schema::hasColumn('award_votes', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('weight');
            }
        });

        // Copy data from award_nomination_id to nomination_id
        if (Schema::hasColumn('award_votes', 'award_nomination_id')) {
            DB::table('award_votes')->whereNotNull('award_nomination_id')->update([
                'nomination_id' => DB::raw('award_nomination_id'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('award_nominations', function (Blueprint $table) {
            // Only drop columns if they exist (for rollback safety)
            $columns = ['award_id', 'category_id', 'nominee_name', 'nominee_artwork', 'nominated_by_id', 'nomination_reason', 'is_official'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('award_nominations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
