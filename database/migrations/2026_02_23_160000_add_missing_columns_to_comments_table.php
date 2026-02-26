<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('status');
            }
            if (! Schema::hasColumn('comments', 'replies_count')) {
                $table->unsignedInteger('replies_count')->default(0)->after('likes_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'replies_count']);
        });
    }
};
