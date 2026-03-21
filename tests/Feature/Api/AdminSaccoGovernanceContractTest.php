<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Sacco\SaccoBoardMeeting;
use App\Models\Sacco\SaccoBoardMeetingAttendance;
use App\Models\Sacco\SaccoBoardMember;
use App\Models\Sacco\SaccoMeeting;
use App\Models\Sacco\SaccoMeetingAttendance;
use App\Models\Sacco\SaccoMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSaccoGovernanceContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->admin->id,
            'role_id' => $role->id,
            'is_active' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        cache()->forget("user:{$this->admin->id}:roles");
    }

    public function test_admin_board_members_endpoint_returns_normalized_member_rows(): void
    {
        $user = User::factory()->create([
            'username' => 'chairperson',
            'email' => 'chair@example.com',
        ]);

        $member = SaccoMember::create([
            'user_id' => $user->id,
            'member_number' => 'MBR-GOV-1',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subYear(),
            'joined_date' => now()->subYear()->toDateString(),
        ]);

        SaccoBoardMember::create([
            'member_id' => $member->id,
            'position' => 'chairperson',
            'term_start_date' => now()->subMonths(3)->toDateString(),
            'term_end_date' => now()->addMonths(9)->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/sacco/board-members')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'chairperson')
            ->assertJsonPath('data.0.role', 'Chairperson')
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_admin_board_meetings_endpoint_returns_standardized_list_and_show_shapes(): void
    {
        $meeting = SaccoBoardMeeting::create([
            'title' => 'Quarterly Governance Review',
            'agenda' => 'Portfolio risk and approvals',
            'scheduled_at' => now()->addWeek(),
            'venue' => 'Main Board Room',
            'status' => 'in_progress',
            'decisions' => ['Approve audit workplan'],
        ]);

        $user = User::factory()->create(['username' => 'secretary']);
        $member = SaccoMember::create([
            'user_id' => $user->id,
            'member_number' => 'MBR-GOV-2',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subMonths(8),
            'joined_date' => now()->subMonths(8)->toDateString(),
        ]);
        $boardMember = SaccoBoardMember::create([
            'member_id' => $member->id,
            'position' => 'secretary',
            'term_start_date' => now()->subMonths(2)->toDateString(),
            'term_end_date' => now()->addMonths(10)->toDateString(),
            'is_active' => true,
        ]);
        SaccoBoardMeetingAttendance::create([
            'meeting_id' => $meeting->id,
            'board_member_id' => $boardMember->id,
            'status' => 'present',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/sacco/board-meetings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.title', 'Quarterly Governance Review')
            ->assertJsonPath('data.0.status', 'ongoing')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/sacco/board-meetings/{$meeting->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ongoing')
            ->assertJsonPath('data.attendees_count', 1)
            ->assertJsonPath('data.quorum_met', true);
    }

    public function test_admin_governance_meetings_support_audit_filters(): void
    {
        SaccoMeeting::create([
            'title' => 'Annual General Meeting',
            'meeting_type' => 'agm',
            'agenda' => 'Adopt annual resolutions',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'minutes' => 'Adopted 2026 workplan',
            'resolutions' => ['Approve audited accounts'],
        ]);

        SaccoMeeting::create([
            'title' => 'Credit Committee Workshop',
            'meeting_type' => 'committee',
            'agenda' => 'Risk review',
            'scheduled_at' => now()->addWeek(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/sacco/meetings?meeting_type=agm&has_resolutions=1&search=annual&date_from='.now()->subWeek()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Annual General Meeting')
            ->assertJsonPath('data.0.meeting_type', 'agm');
    }

    public function test_admin_governance_attendance_summary_flags_repeat_absences(): void
    {
        $engagedUser = User::factory()->create(['username' => 'engaged-member']);
        $engagedMember = SaccoMember::create([
            'user_id' => $engagedUser->id,
            'member_number' => 'MBR-SUM-1',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subMonths(4),
            'joined_date' => now()->subMonths(4)->toDateString(),
        ]);

        $missingUser = User::factory()->create(['username' => 'missing-member']);
        SaccoMember::create([
            'user_id' => $missingUser->id,
            'member_number' => 'MBR-SUM-2',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subMonths(4),
            'joined_date' => now()->subMonths(4)->toDateString(),
        ]);

        $first = SaccoMeeting::create([
            'title' => 'Meeting One',
            'meeting_type' => 'general',
            'scheduled_at' => now()->subDays(10),
            'status' => 'completed',
        ]);

        $second = SaccoMeeting::create([
            'title' => 'Meeting Two',
            'meeting_type' => 'general',
            'scheduled_at' => now()->subDays(5),
            'status' => 'completed',
        ]);

        SaccoMeetingAttendance::create([
            'meeting_id' => $first->id,
            'member_id' => $engagedMember->id,
            'checked_in_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/sacco/meetings/attendance-summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total_meetings', 2)
            ->assertJsonPath('meta.flagged_members', 1)
            ->assertJsonPath('data.0.member_name', 'missing-member')
            ->assertJsonPath('data.0.attendance_flag', 'follow_up');
    }
}
