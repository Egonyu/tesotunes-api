<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            if (! Schema::hasColumn('genres', 'icon')) {
                $table->string('icon', 50)->nullable()->after('color');
            }

            if (! Schema::hasColumn('genres', 'meta_title')) {
                $table->string('meta_title')->nullable()->after('sort_order');
            }

            if (! Schema::hasColumn('genres', 'meta_description')) {
                $table->string('meta_description', 500)->nullable()->after('meta_title');
            }

            if (! Schema::hasColumn('genres', 'meta_keywords')) {
                $table->string('meta_keywords')->nullable()->after('meta_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('genres', 'icon') ? 'icon' : null,
                Schema::hasColumn('genres', 'meta_title') ? 'meta_title' : null,
                Schema::hasColumn('genres', 'meta_description') ? 'meta_description' : null,
                Schema::hasColumn('genres', 'meta_keywords') ? 'meta_keywords' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
