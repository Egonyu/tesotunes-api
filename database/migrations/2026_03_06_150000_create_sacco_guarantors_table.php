<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sacco_guarantors')) {
            return;
        }

        Schema::create('sacco_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('sacco_loans')->cascadeOnDelete();
            $table->foreignId('guarantor_member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->decimal('guaranteed_amount', 15, 2);
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
            $table->index('guarantor_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_guarantors');
    }
};
