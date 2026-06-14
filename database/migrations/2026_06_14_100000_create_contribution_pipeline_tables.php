<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ateso contribution pipeline — the contribute-to-earn translation corpus
 * engine. Built ground-up as one cohesive domain (not a patch). Five tables:
 *
 *   contribution_tasks       — a unit of work (translate / validate a sentence)
 *   contribution_submissions — a contributor's answer to a task
 *   contribution_validations — a peer's verdict on a submission
 *   corpus_pairs             — the accepted, exportable EN<->Ateso pairs
 *   contributor_profiles     — per-contributor reputation, totals, and consent
 *
 * Rewards ride the existing settlement ledger (vertical: contributions) and are
 * never written here. See docs/architecture/ATESO_DATA_PIPELINE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // translate | transcribe | validate. transcribe is reserved for v2
            // (forward-compat) but never dispatched in v1.
            $table->string('type', 20)->index();

            // What this task is derived from: a song lyric line, a curated
            // prompt, or a submission-under-review (for validate tasks).
            $table->nullableMorphs('source');

            $table->string('source_lang', 8)->default('en');
            $table->string('target_lang', 8)->default('teo'); // ISO 639-3 for Ateso/Teso
            $table->string('region', 8)->default('ug');       // ug = Ugandan Ateso
            $table->string('register', 40)->nullable();       // lyrical, proverb, market, ...

            // The text presented to the contributor (e.g. the English line to
            // translate, or the prompt of the day).
            $table->text('prompt_text')->nullable();

            // Gold-standard salting: known-answer items mixed invisibly into the
            // stream to score contributors. gold_answer is never exposed via API.
            $table->boolean('is_gold')->default(false)->index();
            $table->text('gold_answer')->nullable();

            // Redundancy target (N independent translations) before scoring.
            $table->unsignedTinyInteger('redundancy_target')->default(3);

            // open | in_progress | fulfilled | closed
            $table->string('status', 20)->default('open')->index();
            $table->unsignedInteger('submission_count')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['region', 'register']);
        });

        Schema::create('contributor_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Consent capture — one event per contributor. The broad contributor
            // grant + CC-BY-SA-4.0 public release terms, versioned.
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_terms_version', 20)->nullable();

            // novice | trusted | reviewer
            $table->string('tier', 20)->default('novice')->index();

            // Gold pass-rate drives reputation.
            $table->unsignedInteger('gold_attempts')->default(0);
            $table->unsignedInteger('gold_passed')->default(0);
            $table->decimal('gold_pass_rate', 5, 2)->default(0);

            $table->unsignedInteger('submissions_total')->default(0);
            $table->unsignedInteger('submissions_accepted')->default(0);
            $table->unsignedInteger('validations_total')->default(0);
            $table->unsignedBigInteger('credits_earned_total')->default(0);

            $table->boolean('is_suspended')->default(false)->index();
            $table->string('suspended_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('contribution_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('contribution_task_id')->constrained('contribution_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('raw_text');             // the contributor's exact words
            $table->text('normalized_text')->nullable(); // house-orthography form
            $table->string('region', 8)->default('ug');

            // submitted | accepted | rejected | superseded
            $table->string('status', 20)->default('submitted')->index();
            $table->decimal('agreement_score', 5, 2)->nullable();

            // Gold scoring (only set when the parent task is gold).
            $table->boolean('is_gold_attempt')->default(false);
            $table->boolean('gold_passed')->nullable();

            // Reward settlement bookkeeping — the actual money lives in the
            // settlements ledger; these are just local flags.
            $table->boolean('settled')->default(false);
            $table->timestamp('settled_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One submission per contributor per task — no self-stacking.
            $table->unique(['contribution_task_id', 'user_id'], 'cs_task_user_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('contribution_validations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('contribution_submission_id')->constrained('contribution_submissions')->cascadeOnDelete();
            $table->foreignId('validator_user_id')->constrained('users')->cascadeOnDelete();

            // agree | minor_fix | reject
            $table->string('verdict', 20)->index();
            $table->text('suggested_fix')->nullable();

            // Validator trust weight at the time of the vote (trusted tier counts
            // for more); frozen here so later tier changes don't rewrite history.
            $table->decimal('weight', 5, 2)->default(1.00);

            $table->json('metadata')->nullable();
            $table->timestamps();

            // No double-voting; collusion guard (no self/referral validation) is
            // enforced in the service layer, not the schema.
            $table->unique(['contribution_submission_id', 'validator_user_id'], 'cv_submission_validator_unique');
        });

        Schema::create('corpus_pairs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->text('en_text');
            $table->text('ateso_text');
            $table->string('register', 40)->nullable();
            $table->string('region', 8)->default('ug');

            // Provenance back to what produced the pair (song lyric / prompt).
            $table->nullableMorphs('source');
            $table->foreignId('contribution_submission_id')->nullable()->constrained('contribution_submissions')->nullOnDelete();

            // Contributor ids + validation trail, for auditability. Never PII.
            $table->json('provenance')->nullable();
            $table->decimal('quality_score', 5, 2)->nullable();

            $table->string('license_version', 20)->default('CC-BY-SA-4.0');
            $table->string('corpus_version', 30)->nullable()->index();
            $table->timestamp('exported_at')->nullable();

            $table->timestamps();

            $table->index(['region', 'register']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_pairs');
        Schema::dropIfExists('contribution_validations');
        Schema::dropIfExists('contribution_submissions');
        Schema::dropIfExists('contributor_profiles');
        Schema::dropIfExists('contribution_tasks');
    }
};
