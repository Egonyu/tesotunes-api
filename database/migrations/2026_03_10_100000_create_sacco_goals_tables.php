<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_goals')) {
            Schema::create('sacco_goals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('type')->default('general');
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('target_amount', 15, 2);
                $table->decimal('current_amount', 15, 2)->default(0);
                $table->string('currency', 10)->default('UGX');
                $table->date('deadline')->nullable();
                $table->string('status')->default('active');
                $table->string('visibility')->default('private');
                $table->decimal('monthly_target', 15, 2)->nullable();
                $table->boolean('auto_deposit')->default(false);
                $table->decimal('auto_deposit_percentage', 5, 2)->nullable();
                $table->boolean('credit_conversion_enabled')->default(false);
                $table->json('production_details')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'status']);
                $table->index('type');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('sacco_goal_transactions')) {
            Schema::create('sacco_goal_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('goal_id')->constrained('sacco_goals')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('type');
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2)->default(0);
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->string('payment_method')->nullable();
                $table->string('transaction_reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('completed');
                $table->timestamps();

                $table->index(['goal_id', 'created_at']);
                $table->index(['member_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_goal_transactions');
        Schema::dropIfExists('sacco_goals');
    }
};
