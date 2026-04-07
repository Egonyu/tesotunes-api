<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\ModerationReport;
use App\Models\Setting;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminReportsApiTest extends TestCase
{
    use CreatesUsersWithRoles;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

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
            ->assertJsonPath('data.records.0.reason', 'Checkout bug')
            ->assertJsonPath('data.records.0.reported_item', 'Store checkout')
            ->assertJsonPath('data.filters.status', 'pending')
            ->assertJsonPath('data.export.format', 'csv')
            ->assertJsonPath('data.export.filters.status', 'pending')
            ->assertJsonPath('data.export.filters.type', 'bug');

        $this->assertStringContainsString('/api/admin/reports/export?status=pending&type=bug&search=checkout', (string) $response->json('data.export.url'));
        $this->assertStringContainsString('moderation_reports_pending_bug_', (string) $response->json('data.export.filename'));
    }

    public function test_admin_can_export_filtered_reports_as_csv(): void
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

        $response = $this->actingAs($this->admin)->get('/api/admin/reports/export?status=pending&type=bug&search=checkout');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');

        $content = $response->getContent();

        $this->assertStringContainsString('Moderation Reports', $content);
        $this->assertStringContainsString('Status,pending', $content);
        $this->assertStringContainsString('Type,bug', $content);
        $this->assertStringContainsString('Checkout bug', $content);
        $this->assertStringContainsString('Store checkout', $content);
        $this->assertStringNotContainsString('Repeated promo links', $content);
    }

    public function test_admin_can_fetch_streaming_payout_report_grouped_by_rate_source_and_plan(): void
    {
        [$artist, $songOne] = $this->seedStreamingPayoutReportRecords();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/reports/streaming-payouts?rate_source=plan_metadata');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.filters.rate_source', 'plan_metadata')
            ->assertJsonPath('data.summary.total_stream_records', 1)
            ->assertJsonPath('data.summary.total_gross_ugx', 200)
            ->assertJsonPath('data.summary.total_platform_fee_ugx', 50)
            ->assertJsonPath('data.summary.total_net_ugx', 150)
            ->assertJsonPath('data.breakdowns.rate_sources.0.rate_source', 'plan_metadata')
            ->assertJsonPath('data.breakdowns.rate_sources.0.stream_count', 1)
            ->assertJsonPath('data.breakdowns.listener_plans.0.listener_plan_slug', 'gold-monthly')
            ->assertJsonPath('data.breakdowns.listener_plans.0.listener_plan_name', 'Gold Monthly')
            ->assertJsonPath('data.export.format', 'csv')
            ->assertJsonPath('data.export.filters.rate_source', 'plan_metadata')
            ->assertJsonPath('data.records.0.song_title', 'Audit Song One')
            ->assertJsonPath('data.records.0.artist_id', $artist->id)
            ->assertJsonPath('data.records.0.song_id', $songOne->id)
            ->assertJsonPath('data.records.0.audit.rate_source', 'plan_metadata')
            ->assertJsonPath('data.streaming_configuration.streaming_commission_percent', '20.00');

        $this->assertStringContainsString('/api/admin/reports/streaming-payouts/export?rate_source=plan_metadata', (string) $response->json('data.export.url'));
        $this->assertStringContainsString('streaming_payouts_plan_metadata_', (string) $response->json('data.export.filename'));
    }

    public function test_admin_can_export_streaming_payout_report_as_csv(): void
    {
        [$artist, $songOne] = $this->seedStreamingPayoutReportRecords();

        $response = $this->actingAs($this->admin)->get('/api/admin/reports/streaming-payouts/export?rate_source=plan_metadata');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');

        $content = $response->getContent();

        $this->assertStringContainsString('Streaming Payout Report', $content);
        $this->assertStringContainsString('"Rate Source",plan_metadata', $content);
        $this->assertStringContainsString('"Total Stream Records",1', $content);
        $this->assertStringContainsString('Audit Song One', $content);
        $this->assertStringContainsString((string) $artist->id, $content);
        $this->assertStringContainsString((string) $songOne->id, $content);
        $this->assertStringContainsString('gold-monthly', $content);
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

    public function test_moderator_can_access_report_workflow(): void
    {
        $moderator = $this->createUserWithRole('moderator');

        $report = ModerationReport::create([
            'type' => ModerationReport::TYPE_CONTENT,
            'reason' => 'Unsafe content',
            'description' => 'Needs review',
            'status' => ModerationReport::STATUS_PENDING,
            'priority' => ModerationReport::PRIORITY_HIGH,
            'reported_item' => 'Community post',
        ]);

        $this->actingAs($moderator)
            ->getJson('/api/admin/reports/stats')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($moderator)
            ->postJson("/api/admin/reports/{$report->id}/status", [
                'status' => ModerationReport::STATUS_REVIEWING,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function seedStreamingPayoutReportRecords(): array
    {
        Setting::set('platform_commissions', [
            'streaming_percent' => 20,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $artistUser = $this->createUserWithRole('artist');
        $artist = Artist::factory()->verified()->create([
            'user_id' => $artistUser->id,
        ]);

        $songOne = Song::factory()->create([
            'artist_id' => $artist->id,
            'user_id' => $artistUser->id,
            'status' => 'published',
            'title' => 'Audit Song One',
        ]);

        $songTwo = Song::factory()->create([
            'artist_id' => $artist->id,
            'user_id' => $artistUser->id,
            'status' => 'published',
            'title' => 'Audit Song Two',
        ]);

        ArtistRevenue::create([
            'uuid' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'revenue_type' => ArtistRevenue::TYPE_STREAM,
            'sourceable_type' => Song::class,
            'sourceable_id' => $songOne->id,
            'amount_ugx' => 200,
            'amount_usd' => 0.054054,
            'platform_fee' => 50,
            'net_amount' => 150,
            'revenue_date' => now()->toDateString(),
            'status' => ArtistRevenue::STATUS_CONFIRMED,
            'notes' => json_encode([
                'audit_type' => 'stream_payout',
                'listener_plan_slug' => 'gold-monthly',
                'listener_plan_name' => 'Gold Monthly',
                'listener_plan_tier' => 'premium',
                'rate_source' => 'plan_metadata',
            ]),
        ]);

        ArtistRevenue::create([
            'uuid' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'revenue_type' => ArtistRevenue::TYPE_STREAM,
            'sourceable_type' => Song::class,
            'sourceable_id' => $songTwo->id,
            'amount_ugx' => 50,
            'amount_usd' => 0.013513,
            'platform_fee' => 10,
            'net_amount' => 40,
            'revenue_date' => now()->toDateString(),
            'status' => ArtistRevenue::STATUS_CONFIRMED,
            'notes' => json_encode([
                'audit_type' => 'stream_payout',
                'listener_plan_slug' => 'free',
                'listener_plan_name' => 'Free',
                'listener_plan_tier' => 'free',
                'rate_source' => 'default_free',
            ]),
        ]);

        return [$artist, $songOne];
    }
}
