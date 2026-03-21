<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'ticketing_mode')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $column = $table->string('ticketing_mode', 30)
                ->default('tesotunes_managed');

            if (Schema::hasColumn('events', 'is_free')) {
                $column->after('is_free');
            }
        });

        if (Schema::hasColumn('events', 'is_free')) {
            DB::table('events')
                ->where('is_free', true)
                ->update(['ticketing_mode' => 'free_rsvp']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'ticketing_mode')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('ticketing_mode');
            });
        }
    }
};
