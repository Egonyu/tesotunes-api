<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comments')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false);
            }
            if (! Schema::hasColumn('comments', 'replies_count')) {
                $table->unsignedInteger('replies_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('comments')) {
            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('comments', 'is_pinned')) {
                $columns[] = 'is_pinned';
            }
            if (Schema::hasColumn('comments', 'replies_count')) {
                $columns[] = 'replies_count';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
