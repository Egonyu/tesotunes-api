<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account capability grants — the authorization source of truth for what a
 * user is allowed to sell or run on the platform (artist, seller, organizer,
 * promoter, label). One account, many capabilities; each granted through the
 * same apply -> review -> grant lifecycle behind the single KYC gate.
 * See docs/architecture/CAPABILITIES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capability', 20);
            $table->string('status', 20)->default('pending');

            // Domain profile backing the capability: artists row, stores row,
            // promoter_profiles row. Organizer has no entity yet (null).
            $table->nullableMorphs('profile');

            // Lifecycle audit trail.
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_reason')->nullable();

            // The application payload submitted by the user (form answers).
            $table->json('application')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'capability']);
            $table->index(['capability', 'status'], 'user_capabilities_capability_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
