<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            });
        }

        if (Schema::hasTable('user_security_profiles') && ! Schema::hasColumn('user_security_profiles', 'two_factor_confirmed_at')) {
            Schema::table('user_security_profiles', function (Blueprint $table) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            });
        }

        if (Schema::hasTable('users') && Schema::hasTable('user_security_profiles')) {
            DB::table('user_security_profiles')
                ->join('users', 'users.id', '=', 'user_security_profiles.user_id')
                ->whereNull('user_security_profiles.two_factor_confirmed_at')
                ->where('user_security_profiles.two_factor_enabled', true)
                ->update([
                    'user_security_profiles.two_factor_confirmed_at' => DB::raw('users.two_factor_confirmed_at'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_security_profiles') && Schema::hasColumn('user_security_profiles', 'two_factor_confirmed_at')) {
            Schema::table('user_security_profiles', function (Blueprint $table) {
                $table->dropColumn('two_factor_confirmed_at');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_factor_confirmed_at');
            });
        }
    }
};
