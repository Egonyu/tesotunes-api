<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'is_secret')) {
                $table->boolean('is_secret')->default(false)->after('is_public');
            }

            if (! Schema::hasColumn('settings', 'last_updated_by')) {
                $table->foreignId('last_updated_by')
                    ->nullable()
                    ->after('is_secret')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('settings', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('last_updated_by');
            }
        });

        $indexes = collect(Schema::getIndexes('settings'))->pluck('name')->all();

        if (! in_array('settings_group_index', $indexes, true)) {
            Schema::table('settings', function (Blueprint $table) {
                $table->index('group', 'settings_group_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('settings'))->pluck('name')->all();

            if (in_array('settings_group_index', $indexes, true)) {
                $table->dropIndex('settings_group_index');
            }

            if (Schema::hasColumn('settings', 'last_updated_by')) {
                $table->dropConstrainedForeignId('last_updated_by');
            }

            if (Schema::hasColumn('settings', 'version')) {
                $table->dropColumn('version');
            }

            if (Schema::hasColumn('settings', 'is_secret')) {
                $table->dropColumn('is_secret');
            }
        });
    }
};
