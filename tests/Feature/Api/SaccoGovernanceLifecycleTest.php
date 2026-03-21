<?php

use App\Models\Role;
use App\Models\Sacco\SaccoMeeting;
use App\Models\Sacco\SaccoMeetingAttendance;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('members can list governance meetings and rsvp', function () {
    $user = User::factory()->create();
    $member = SaccoMember::create([
        'user_id' => $user->id,
        'member_number' => 'MBR-GOV-M1',
        'status' => SaccoMember::STATUS_ACTIVE,
        'joined_at' => now()->subMonths(4),
        'joined_date' => now()->subMonths(4)->toDateString(),
    ]);

    $meeting = SaccoMeeting::create([
        'title' => 'Annual General Meeting',
        'meeting_type' => 'general',
        'description' => 'Annual accountability session',
        'agenda' => 'Approve annual budget',
        'location' => 'Community Hall',
        'scheduled_at' => now()->addDays(3),
        'quorum_required' => 1,
        'status' => 'scheduled',
        'resolutions' => ['Adopt audit recommendations'],
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/sacco/meetings')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Annual General Meeting')
        ->assertJsonPath('data.0.is_attending', false)
        ->assertJsonPath('data.0.resolutions.0', 'Adopt audit recommendations');

    $this->postJson("/api/sacco/meetings/{$meeting->id}/rsvp", [
        'attending' => true,
    ])->assertOk()
        ->assertJsonPath('message', 'RSVP updated successfully.')
        ->assertJsonPath('data.is_attending', true)
        ->assertJsonPath('data.quorum_met', true);

    $this->getJson('/api/sacco/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.type', 'governance_rsvp_confirmed');

    expect(SaccoMeetingAttendance::where('meeting_id', $meeting->id)->where('member_id', $member->id)->exists())->toBeTrue();

    $this->postJson("/api/sacco/meetings/{$meeting->id}/rsvp", [
        'attending' => false,
    ])->assertOk()
        ->assertJsonPath('data.is_attending', false);
});

test('admins can manage governance meetings and attendance', function () {
    $admin = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
    );
    DB::table('user_roles')->insert([
        'user_id' => $admin->id,
        'role_id' => $role->id,
        'is_active' => true,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    cache()->forget("user:{$admin->id}:roles");

    $memberUser = User::factory()->create(['username' => 'delegate']);
    $member = SaccoMember::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MBR-GOV-A1',
        'status' => SaccoMember::STATUS_ACTIVE,
        'joined_at' => now()->subMonths(6),
        'joined_date' => now()->subMonths(6)->toDateString(),
    ]);

    $this->actingAs($admin)
        ->postJson('/api/admin/sacco/meetings', [
            'title' => 'Credit Committee',
            'meeting_type' => 'committee',
            'description' => 'Loan review forum',
            'agenda' => 'Review large loans',
            'scheduled_at' => now()->addDay()->toISOString(),
            'location' => 'HQ Boardroom',
            'quorum_required' => 1,
            'resolutions' => ['Pre-approve member education agenda'],
        ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Credit Committee')
        ->assertJsonPath('data.resolutions.0', 'Pre-approve member education agenda');

    expect(SaccoNotification::query()->where('type', 'governance_meeting_scheduled')->count())->toBe(1);

    $meetingId = SaccoMeeting::query()->value('id');

    $this->actingAs($admin)
        ->postJson("/api/admin/sacco/meetings/{$meetingId}/attendance", [
            'member_id' => $member->id,
            'attending' => true,
        ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.attendees_count', 1)
        ->assertJsonPath('data.quorum_met', true);

    $this->actingAs($admin)
        ->getJson("/api/admin/sacco/meetings/{$meetingId}/attendance")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.member_name', 'delegate');

    $this->actingAs($admin)
        ->putJson("/api/admin/sacco/meetings/{$meetingId}", [
            'status' => 'completed',
            'minutes' => 'Meeting closed with unanimous approval.',
            'resolutions' => ['Increase governance training budget'],
        ])->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.minutes', 'Meeting closed with unanimous approval.')
        ->assertJsonPath('data.resolutions.0', 'Increase governance training budget');

    expect(SaccoNotification::query()->where('type', 'governance_resolutions_published')->count())->toBe(1);
});
