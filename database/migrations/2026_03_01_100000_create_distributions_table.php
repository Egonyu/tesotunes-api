<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distributions')) {
            Schema::create('distributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('song_id')->constrained('songs')->cascadeOnDelete();
                $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
                $table->string('platform_code', 50)->index();
                $table->string('platform_name', 100);
                $table->string('status', 30)->default('pending')->index();
                $table->string('platform_url')->nullable();
                $table->string('platform_id')->nullable();
                $table->json('platform_metadata')->nullable();
                $table->json('distribution_metadata')->nullable();
                $table->timestamp('live_date')->nullable();
                $table->timestamp('removed_date')->nullable();
                $table->string('removal_reason')->nullable();
                $table->timestamp('removal_requested_at')->nullable();
                $table->text('error_message')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->unsignedBigInteger('total_streams')->default(0);
                $table->decimal('total_revenue', 12, 2)->default(0);
                $table->timestamp('last_synced')->nullable();
                $table->timestamp('last_updated')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Composite indexes for common queries
                $table->index(['song_id', 'status']);
                $table->index(['artist_id', 'status']);
                $table->unique(['song_id', 'platform_code'], 'distributions_song_platform_unique');
            });
        }

        // Also create distribution_revenue table referenced by DistributionService
        if (! Schema::hasTable('distribution_revenue')) {
            Schema::create('distribution_revenue', function (Blueprint $table) {
                $table->id();
                $table->foreignId('distribution_id')->constrained('distributions')->cascadeOnDelete();
                $table->string('reporting_period', 20);
                $table->unsignedBigInteger('streams')->default(0);
                $table->decimal('revenue', 12, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->timestamps();

                $table->unique(['distribution_id', 'reporting_period'], 'dist_rev_period_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_revenue');
        Schema::dropIfExists('distributions');
    }
};
