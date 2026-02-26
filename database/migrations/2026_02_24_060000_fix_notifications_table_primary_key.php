<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only run if notifications table exists and has uuid primary key
        if (Schema::hasTable('notifications')) {
            $columns = Schema::getColumnListing('notifications');
            if (in_array('id', $columns) && Schema::getColumnType('notifications', 'id') === 'uuid') {
                // Drop and recreate table with integer id
                Schema::drop('notifications');
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
    }

    public function down(): void
    {
        // Optionally restore uuid primary key
        if (Schema::hasTable('notifications')) {
            Schema::drop('notifications');
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }
    }
};
