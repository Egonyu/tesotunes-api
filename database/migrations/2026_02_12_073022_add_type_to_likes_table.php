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
        Schema::table('likes', function (Blueprint $table) {
            $table->string('type', 20)->default('like')->after('likeable_id')
                ->comment('like or bookmark');
            $table->index(['user_id', 'likeable_type', 'likeable_id', 'type'], 'likes_unique_interaction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropIndex('likes_unique_interaction');
            $table->dropColumn('type');
        });
    }
};
