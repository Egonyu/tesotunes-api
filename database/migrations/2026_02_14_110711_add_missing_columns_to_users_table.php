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
        Schema::table('users', function (Blueprint $table) {
            // Add uuid column (used by User model boot)
            if (!Schema::hasColumn('users', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }

            // Add display_name (model mutator maps 'name' -> 'display_name')
            if (!Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }

            // Add phone column (model uses 'phone', DB has 'phone_number')
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->after('phone_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'display_name', 'phone']);
        });
    }
};
