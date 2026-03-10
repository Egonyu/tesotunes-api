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
        Schema::table('songs', function (Blueprint $table) {
            if (! Schema::hasColumn('songs', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('songs', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('songs', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('songs', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('rejection_reason');
            }
            if (! Schema::hasColumn('songs', 'moderated_at')) {
                $table->timestamp('moderated_at')->nullable()->after('review_notes');
            }
            if (! Schema::hasColumn('songs', 'moderated_by')) {
                $table->unsignedBigInteger('moderated_by')->nullable()->after('moderated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $columns = ['approved_at', 'approved_by', 'rejection_reason', 'review_notes', 'moderated_at', 'moderated_by'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('songs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
