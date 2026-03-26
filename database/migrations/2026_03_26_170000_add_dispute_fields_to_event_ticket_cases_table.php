<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_ticket_cases', function (Blueprint $table) {
            $table->string('dispute_category', 60)->nullable()->after('case_type');
            $table->string('gateway_reference', 120)->nullable()->after('reason');
            $table->string('evidence_url', 2048)->nullable()->after('gateway_reference');
            $table->text('evidence_notes')->nullable()->after('evidence_url');
            $table->string('escalation_status', 40)->default('none')->after('status');

            $table->index(['case_type', 'escalation_status'], 'etc_case_escalation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_ticket_cases', function (Blueprint $table) {
            $table->dropIndex('etc_case_escalation_idx');
            $table->dropColumn([
                'dispute_category',
                'gateway_reference',
                'evidence_url',
                'evidence_notes',
                'escalation_status',
            ]);
        });
    }
};
