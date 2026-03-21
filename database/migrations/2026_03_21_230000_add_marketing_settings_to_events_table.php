<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'marketing_settings')) {
                $column = $table->json('marketing_settings')->nullable();

                if (Schema::hasColumn('events', 'social_links')) {
                    $column->after('social_links');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'marketing_settings')) {
                $table->dropColumn('marketing_settings');
            }
        });
    }
};
