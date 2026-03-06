<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==========================================
        // SACCO CONTRIBUTIONS
        // ==========================================
        if (! Schema::hasTable('sacco_contributions')) {
            Schema::create('sacco_contributions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('contribution_number')->unique();
                $table->string('type')->default('monthly'); // monthly, special, penalty, joining_fee
                $table->decimal('amount_ugx', 15, 2);
                $table->string('payment_method')->default('mobile_money');
                $table->string('transaction_reference')->nullable();
                $table->date('contribution_date');
                $table->string('period')->nullable(); // e.g. "2026-03" for monthly
                $table->string('status')->default('pending'); // pending, confirmed, failed, reversed
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'contribution_date']);
                $table->index(['status', 'contribution_date']);
                $table->index('period');
            });
        }

        // ==========================================
        // SACCO GROUPS (group savings circles)
        // ==========================================
        if (! Schema::hasTable('sacco_groups')) {
            Schema::create('sacco_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('group_number')->unique();
                $table->text('description')->nullable();
                $table->foreignId('leader_id')->nullable()->constrained('sacco_members')->nullOnDelete();
                $table->integer('max_members')->default(30);
                $table->decimal('target_amount_ugx', 15, 2)->default(0);
                $table->decimal('collected_amount_ugx', 15, 2)->default(0);
                $table->string('contribution_frequency')->default('monthly'); // weekly, monthly
                $table->decimal('minimum_contribution_ugx', 15, 2)->default(0);
                $table->string('status')->default('active'); // active, completed, dissolved
                $table->timestamps();

                $table->index('status');
            });
        }

        // Pivot table for group membership
        if (! Schema::hasTable('sacco_group_members')) {
            Schema::create('sacco_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('sacco_groups')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('role')->default('member'); // member, secretary, treasurer
                $table->timestamp('joined_at')->useCurrent();

                $table->unique(['group_id', 'member_id']);
            });
        }

        // ==========================================
        // SACCO MEETINGS (general / AGM meetings)
        // ==========================================
        if (! Schema::hasTable('sacco_meetings')) {
            Schema::create('sacco_meetings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('title');
                $table->string('meeting_type')->default('general'); // general, agm, special, emergency
                $table->text('description')->nullable();
                $table->text('agenda')->nullable();
                $table->string('location')->nullable();
                $table->dateTime('scheduled_at');
                $table->dateTime('ended_at')->nullable();
                $table->integer('quorum_required')->default(0);
                $table->integer('attendees_count')->default(0);
                $table->text('minutes')->nullable();
                $table->text('resolutions')->nullable();
                $table->string('status')->default('scheduled'); // scheduled, in_progress, completed, cancelled
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'scheduled_at']);
            });
        }

        // Meeting attendance (separate from board_meeting_attendance)
        if (! Schema::hasTable('sacco_meeting_attendances')) {
            Schema::create('sacco_meeting_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained('sacco_meetings')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->timestamp('checked_in_at')->nullable();
                $table->boolean('proxy')->default(false);
                $table->string('proxy_name')->nullable();

                $table->unique(['meeting_id', 'member_id']);
            });
        }

        // ==========================================
        // SACCO FINES
        // ==========================================
        if (! Schema::hasTable('sacco_fines')) {
            Schema::create('sacco_fines', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('fine_number')->unique();
                $table->string('reason'); // late_payment, missed_meeting, rule_violation, loan_default
                $table->text('description')->nullable();
                $table->decimal('amount_ugx', 15, 2);
                $table->decimal('amount_paid_ugx', 15, 2)->default(0);
                $table->date('due_date')->nullable();
                $table->date('paid_at')->nullable();
                $table->string('status')->default('pending'); // pending, paid, waived, overdue
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('waiver_reason')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'status']);
                $table->index('status');
            });
        }

        // ==========================================
        // SACCO WITHDRAWAL REQUESTS
        // ==========================================
        if (! Schema::hasTable('sacco_withdrawal_requests')) {
            Schema::create('sacco_withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('sacco_savings_accounts')->nullOnDelete();
                $table->string('request_number')->unique();
                $table->decimal('amount_ugx', 15, 2);
                $table->decimal('fee_ugx', 15, 2)->default(0);
                $table->decimal('net_amount_ugx', 15, 2)->default(0);
                $table->string('withdrawal_method')->default('mobile_money');
                $table->string('phone_number')->nullable();
                $table->string('reason')->nullable();
                $table->string('status')->default('pending'); // pending, approved, processing, completed, rejected
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('transaction_reference')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'status']);
                $table->index('status');
            });
        }

        // ==========================================
        // SACCO NOTIFICATIONS (module-specific)
        // ==========================================
        if (! Schema::hasTable('sacco_notifications')) {
            Schema::create('sacco_notifications', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('type'); // loan_approved, payment_due, fine_issued, meeting_scheduled, etc.
                $table->string('title');
                $table->text('message');
                $table->string('channel')->default('in_app'); // in_app, sms, email
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'read_at']);
                $table->index('type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_notifications');
        Schema::dropIfExists('sacco_withdrawal_requests');
        Schema::dropIfExists('sacco_fines');
        Schema::dropIfExists('sacco_meeting_attendances');
        Schema::dropIfExists('sacco_meetings');
        Schema::dropIfExists('sacco_group_members');
        Schema::dropIfExists('sacco_groups');
        Schema::dropIfExists('sacco_contributions');
    }
};
