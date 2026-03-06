<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_usage_logs')) {
            return;
        }

        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('endpoint', 255)->index();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('response_time_ms');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('requested_at')->useCurrent()->index();

            $table->index(['endpoint', 'requested_at']);
            $table->index(['user_id', 'requested_at']);
            $table->index(['status_code', 'requested_at']);
        });

        // Hourly aggregation table for fast dashboard queries
        Schema::create('api_usage_hourly', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->date('date')->index();
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('client_error_count')->default(0);
            $table->unsignedInteger('server_error_count')->default(0);
            $table->unsignedInteger('avg_response_ms')->default(0);
            $table->unsignedInteger('max_response_ms')->default(0);
            $table->unsignedInteger('unique_users')->default(0);

            $table->unique(['endpoint', 'method', 'date', 'hour'], 'api_usage_hourly_unique');
            $table->index(['date', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_hourly');
        Schema::dropIfExists('api_usage_logs');
    }
};
