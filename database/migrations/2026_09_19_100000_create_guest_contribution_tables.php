<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest contribution loop — the unauthenticated front door to the Ateso corpus.
 *
 * A visitor types their own sentence, sees the model's attempt, says whether it
 * is right, and (when it is not) supplies the correction. None of that requires
 * an account: the only people who ever contributed to the prototype did so
 * anonymously, and a consent gate on arrival converts them to zero.
 *
 * Rows live here keyed by an opaque session key until the visitor signs in, at
 * which point GuestClaimService promotes them into the normal pipeline
 * (contribution_tasks + contribution_submissions) and the existing quality gate,
 * tiers and settlement take over unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_contributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Opaque per-browser key minted client-side. Not a user identifier and
            // never exposed to other visitors.
            $table->string('session_key', 64)->index();

            // teo_to_en | en_to_teo — which way the visitor asked for.
            $table->string('direction', 12);

            $table->text('source_text');          // exactly what they typed
            $table->text('model_output');         // what the translator returned

            // correct | wrong | skipped. A "correct" verdict is as valuable as a
            // correction: it is the only signal that tells us the model improved.
            $table->string('verdict', 12)->nullable()->index();

            // Their corrected translation, when they supplied one.
            $table->text('correction')->nullable();

            $table->string('dialect', 16)->nullable();
            $table->boolean('is_code_switched')->default(false);

            // Where the source sentence came from: typed | suggested. Suggested
            // rows were seeded prompts the visitor picked rather than composed.
            $table->string('origin', 12)->default('typed')->index();

            // Salted hash only — raw IPs are never stored.
            $table->string('ip_hash', 64)->nullable()->index();

            // Set when promoted into contribution_submissions on sign-in.
            $table->foreignId('claimed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            // The claim sweep reads unclaimed rows for one session key.
            $table->index(['session_key', 'claimed_at'], 'gc_session_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_contributions');
    }
};
