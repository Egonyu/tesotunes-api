<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (! Schema::hasColumn('songs', 'distribution_status')) {
                $table->string('distribution_status', 30)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('songs', 'distribution_requested_at')) {
                $table->timestamp('distribution_requested_at')->nullable()->after('distribution_status');
            }

            if (! Schema::hasColumn('songs', 'distributed_at')) {
                $table->timestamp('distributed_at')->nullable()->after('distribution_requested_at');
            }

            if (! Schema::hasColumn('songs', 'distribution_platforms')) {
                $table->json('distribution_platforms')->nullable()->after('distributed_at');
            }
        });

        if (Schema::hasColumn('songs', 'distribution_status')) {
            DB::table('songs')
                ->whereNull('distribution_status')
                ->update([
                    'distribution_status' => DB::raw("
                        CASE
                            WHEN status = 'published' AND approved_at IS NOT NULL THEN 'approved'
                            WHEN status IN ('pending', 'pending_review') THEN 'pending_review'
                            WHEN status = 'rejected' THEN 'rejected'
                            ELSE 'draft'
                        END
                    "),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (Schema::hasColumn('songs', 'distribution_platforms')) {
                $table->dropColumn('distribution_platforms');
            }

            if (Schema::hasColumn('songs', 'distributed_at')) {
                $table->dropColumn('distributed_at');
            }

            if (Schema::hasColumn('songs', 'distribution_requested_at')) {
                $table->dropColumn('distribution_requested_at');
            }

            if (Schema::hasColumn('songs', 'distribution_status')) {
                $table->dropColumn('distribution_status');
            }
        });
    }
};
