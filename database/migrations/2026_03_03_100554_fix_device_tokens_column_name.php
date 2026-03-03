<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix column name mismatch: migration created 'token' but DeviceToken model
     * and PushNotificationService reference 'device_token'. Also align
     * 'device_type'/'device_name' → 'device_info' JSON column to match model.
     *
     * This mismatch causes registration to fail with:
     * SQLSTATE[42S22]: Column not found: 1054 Unknown column 'device_token'
     */
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            // Rename 'token' → 'device_token' to match DeviceToken model $fillable
            if (Schema::hasColumn('device_tokens', 'token') && ! Schema::hasColumn('device_tokens', 'device_token')) {
                $table->renameColumn('token', 'device_token');
            }

            // Add 'device_info' JSON column to match model $fillable + $casts
            if (! Schema::hasColumn('device_tokens', 'device_info')) {
                $table->json('device_info')->nullable()->after('platform');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('device_tokens', 'device_token') && ! Schema::hasColumn('device_tokens', 'token')) {
                $table->renameColumn('device_token', 'token');
            }

            if (Schema::hasColumn('device_tokens', 'device_info')) {
                $table->dropColumn('device_info');
            }
        });
    }
};
