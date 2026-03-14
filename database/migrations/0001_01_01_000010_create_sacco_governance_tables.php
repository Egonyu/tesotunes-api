<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['model_type', 'model_id']);
            $table->index(['user_id', 'action']);
        });

        Schema::create('sacco_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('position');
            $table->date('term_start_date')->nullable();
            $table->date('term_end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
        });

        Schema::create('sacco_board_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->timestamp('scheduled_at');
            $table->string('venue')->nullable();
            $table->string('status')->default('scheduled');
            $table->text('minutes')->nullable();
            $table->json('decisions')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('sacco_board_meeting_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('sacco_board_meetings')->cascadeOnDelete();
            $table->foreignId('board_member_id')->constrained('sacco_board_members')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'board_member_id']);
        });

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
            $table->string('contribution_frequency')->default('monthly');
            $table->decimal('minimum_contribution_ugx', 15, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['leader_id', 'status']);
        });

        Schema::create('sacco_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('sacco_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['group_id', 'member_id']);
        });

        Schema::create('sacco_meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('meeting_type')->default('general');
            $table->text('description')->nullable();
            $table->text('agenda')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('quorum_required')->default(0);
            $table->integer('attendees_count')->default(0);
            $table->text('minutes')->nullable();
            $table->text('resolutions')->nullable();
            $table->string('status')->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('sacco_meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('sacco_meetings')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->boolean('proxy')->default(false);
            $table->string('proxy_name')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'member_id']);
        });

        Schema::create('sacco_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('channel')->default('in_app');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'read_at']);
            $table->index(['type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_notifications');
        Schema::dropIfExists('sacco_meeting_attendances');
        Schema::dropIfExists('sacco_meetings');
        Schema::dropIfExists('sacco_group_members');
        Schema::dropIfExists('sacco_groups');
        Schema::dropIfExists('sacco_board_meeting_attendance');
        Schema::dropIfExists('sacco_board_meetings');
        Schema::dropIfExists('sacco_board_members');
        Schema::dropIfExists('sacco_audit_logs');
    }
};
