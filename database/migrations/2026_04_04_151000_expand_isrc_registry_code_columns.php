<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isrc_codes', function (Blueprint $table) {
            if (Schema::hasColumn('isrc_codes', 'code')) {
                $table->string('code', 20)->change();
            }

            if (Schema::hasColumn('isrc_codes', 'isrc_code')) {
                $table->string('isrc_code', 20)->nullable()->change();
            }

            if (Schema::hasColumn('isrc_codes', 'formatted_isrc')) {
                $table->string('formatted_isrc', 20)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('isrc_codes', function (Blueprint $table) {
            if (Schema::hasColumn('isrc_codes', 'formatted_isrc')) {
                $table->string('formatted_isrc', 20)->nullable()->change();
            }

            if (Schema::hasColumn('isrc_codes', 'isrc_code')) {
                $table->string('isrc_code', 20)->nullable()->change();
            }

            if (Schema::hasColumn('isrc_codes', 'code')) {
                $table->string('code', 12)->change();
            }
        });
    }
};
