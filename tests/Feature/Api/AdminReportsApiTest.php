<?php

namespace Tests\Feature\Api;

use App\Models\ModerationReport;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminReportsApiTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('moderation_reports')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_03_20_000100_create_moderation_reports_table.php',
                '--force' => true,
            ]);
        }

        ModerationReport::query()->delete();

        $this->admin = $this->createUserWithRole('admin');
    }

    public function test_admin_can_fetch_report_stats(): void
    {
        ModerationReport::create([
            'type' => ModerationReport::TYPE_BUG,
            'reason' => 'Playback bug',
            'description' => 'Player freezes on Safari',
            'status' => ModerationReport::STATUS_PENDING,
            'priority' => ModerationReport::PRIORITY_HIGH,
            'reported_item' => 'Web player',
        ]);

        ModerationReport::create([
            'type' => ModerationReport::TYPE_SONG,
            'reason' => 'Explicit content not labelled',
            'description' => 'Song metadata is missing explicit flag',
            'status' => ModerationReport::STATUS_REVIEWING,
            'priority' => ModerationReport::PRIORITY_MEDIUM,
            'reported_item' => 'Evening Vibes',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/reports/stats');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.reviewing', 1);
    }

    public function test_admin_can_filter_and_search_reports(): void
    {
        $reporter = $this->createUserWithRole('artist');

        ModerationReport::create([
            'type' => ModerationReport::TYPE_BUG,
            'reason' => 'Checkout bug',
            'description' => 'Store checkout fails for mobile money',
            'status' => ModerationReport::STATUS_PENDING,
            'priority' => ModerationReport::PRIORITY_CRITICAL,
            'reported_by_user_id' => $reporter->id,
            'reported_item' => 'Store checkout',
        ]);

        ModerationReport::create([
            'type' => ModerationReport::TYPE_COMMENT,
            'reason' => 'Spam',
            'description' => 'Repeated promo links',
            'status' => ModerationReport::STATUS_RESOLVED,
            'priority' => ModerationReport::PRIORITY_LOW,
            'reported_item' => 'Comment #442',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/reports?status=pending&type=bug&search=checkout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reason', 'Checkout bug')
            ->assertJsonPath('data.0.reported_item', 'Store checkout');
    }

    public function test_admin_can_update_report_status(): void
    {
        $report = ModerationReport::create([
            'type' => ModerationReport::TYPE_CONTENT,
            'reason' => 'Unsafe content',
            'description' => 'Needs review',
            'status' => ModerationReport::STATUS_PENDING,
            'priority' => ModerationReport::PRIORITY_HIGH,
            'reported_item' => 'Community post',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/reports/{$report->id}/status", [
            'status' => ModerationReport::STATUS_REVIEWING,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ModerationReport::STATUS_REVIEWING);

        $report->refresh();

        $this->assertSame(ModerationReport::STATUS_REVIEWING, $report->status);
        $this->assertSame($this->admin->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);
    }
}
