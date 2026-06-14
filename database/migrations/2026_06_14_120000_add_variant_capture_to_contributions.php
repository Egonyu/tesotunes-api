<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dialect & variant capture (9.7). Ateso is dialect-rich (Soroti, Kumi, Ngora,
 * Katakwi, Amuria, Pallisa, Tororo, Kenya-Teso …) and heavily code-switched
 * (English/Swahili/Luganda). A single "canonical" answer per prompt loses real,
 * valid variants — so submissions and corpus pairs carry a dialect tag and a
 * code-switch flag, and gold items may accept a set of correct forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_submissions', function (Blueprint $table) {
            $table->string('dialect', 16)->nullable()->after('region')->index();
            $table->boolean('is_code_switched')->default(false)->after('dialect');
            $table->text('note')->nullable()->after('is_code_switched');
        });

        Schema::table('corpus_pairs', function (Blueprint $table) {
            $table->string('dialect', 16)->nullable()->after('region')->index();
            $table->boolean('is_code_switched')->default(false)->after('dialect');
        });

        Schema::table('contributor_profiles', function (Blueprint $table) {
            $table->string('dialect', 16)->nullable()->after('consent_terms_version');
        });

        Schema::table('contribution_tasks', function (Blueprint $table) {
            // Additional acceptable gold answers (dialectal forms), checked
            // alongside gold_answer so a valid variant never fails a gold.
            $table->json('gold_answers')->nullable()->after('gold_answer');
        });
    }

    public function down(): void
    {
        Schema::table('contribution_submissions', function (Blueprint $table) {
            $table->dropColumn(['dialect', 'is_code_switched', 'note']);
        });
        Schema::table('corpus_pairs', function (Blueprint $table) {
            $table->dropColumn(['dialect', 'is_code_switched']);
        });
        Schema::table('contributor_profiles', function (Blueprint $table) {
            $table->dropColumn('dialect');
        });
        Schema::table('contribution_tasks', function (Blueprint $table) {
            $table->dropColumn('gold_answers');
        });
    }
};
