<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->string('issue_type', 50)->index(); // stuck_processing, provider_error, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('open')->index(); // open, investigating, resolved, escalated, closed
            $table->string('severity', 20)->default('medium'); // low, medium, high, critical
            $table->boolean('money_deducted')->default(false);
            $table->boolean('service_delivered')->default(false);
            $table->string('provider_status')->nullable();
            $table->string('resolution_type', 30)->nullable(); // auto_resolved, manual, refunded, retried, false_positive
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('auto_resolve_attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['issue_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_issues');
    }
};
