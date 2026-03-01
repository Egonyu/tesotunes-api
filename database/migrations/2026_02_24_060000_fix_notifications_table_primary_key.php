<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            // Table doesn't exist — create it fresh with integer id
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });

            return;
        }

        // Table exists — check if it needs conversion from uuid to integer id
        $columns = Schema::getColumnListing('notifications');
        if (! in_array('id', $columns)) {
            return; // No id column, skip
        }

        $columnType = Schema::getColumnType('notifications', 'id');
        // UUID columns appear as 'string' or 'guid' depending on the driver
        if (in_array($columnType, ['string', 'guid'])) {
            Schema::dropIfExists('notifications');
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        // No-op: we don't want to destroy notifications data going backwards
    }
};
