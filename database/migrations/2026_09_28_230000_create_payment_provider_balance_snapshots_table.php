<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->index();
            $table->string('currency', 3)->default('UGX');
            $table->decimal('balance', 15, 2);
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['provider', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_balance_snapshots');
    }
};
