<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce core settlement ledger — the single place seller-side money lands
 * for every vertical (store sales, event tickets, promotion services, music
 * revenue). See docs/architecture/COMMERCE_CORE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Beneficiary is always a user — capability-agnostic (artist,
            // merchant, organizer, promoter are users with grants).
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();

            // Reporting dimensions.
            $table->string('vertical', 20);
            $table->string('kind', 30);

            // The transaction that produced the money (store order, event
            // attendee, promotion order item, artist revenue event, ...).
            $table->morphs('source');

            // Hybrid-currency amounts; net = gross - fee (service-enforced).
            $table->decimal('gross_ugx', 14, 2)->default(0);
            $table->decimal('fee_ugx', 14, 2)->default(0);
            $table->decimal('net_ugx', 14, 2)->default(0);
            $table->unsignedInteger('gross_credits')->default(0);
            $table->unsignedInteger('fee_credits')->default(0);
            $table->unsignedInteger('net_credits')->default(0);

            // Lifecycle: pending -> cleared -> paid_out, reversed from pending/cleared.
            $table->string('status', 20)->default('pending');
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            // The payout that disbursed this row (artist_payouts today,
            // unified payouts later — the morph absorbs that transition).
            $table->nullableMorphs('payout');

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'beneficiary_user_id', 'kind'],
                'settlements_source_beneficiary_kind_unique'
            );
            $table->index(['beneficiary_user_id', 'status'], 'settlements_beneficiary_status_idx');
            $table->index(['vertical', 'status'], 'settlements_vertical_status_idx');
            $table->index(['status', 'hold_until'], 'settlements_clearance_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
