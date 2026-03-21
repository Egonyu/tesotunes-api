<?php

namespace Tests\Feature\Api;

use App\Models\ApiUsageHourly;
use App\Models\ApiUsageLog;
use App\Models\AuditLog;
use App\Models\Download;
use App\Models\PlayHistory;
use App\Models\Role;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminAdminOperationsApiTest extends TestCase
{
    use CreatesUsersWithRoles;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::factory()->admin()->create();
        Role::factory()->user()->create();

        $this->admin = $this->createUserWithRole('admin');
    }

    public function test_admin_analytics_endpoints_return_dashboard_contracts(): void
    {
        $consumer = User::factory()->create([
            'name' => 'API Consumer',
            'email' => 'consumer@example.com',
        ]);

        ApiUsageHourly::query()->create([
            'endpoint' => '/api/admin/analytics',
            'method' => 'GET',
            'date' => now()->toDateString(),
            'hour' => (int) now()->format('G'),
            'total_requests' => 25,
            'success_count' => 23,
            'client_error_count' => 1,
            'server_error_count' => 1,
            'avg_response_ms' => 120,
            'max_response_ms' => 260,
            'unique_users' => 2,
        ]);

        ApiUsageLog::query()->create([
            'user_id' => $consumer->id,
            'method' => 'GET',
            'endpoint' => '/api/admin/analytics',
            'status_code' => 200,
            'response_time_ms' => 111,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'requested_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/analytics?range=30d')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'metrics',
                    'top_countries',
                    'revenue_breakdown',
                    'streams_chart',
                    'peak_hours',
                ],
            ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/analytics/api-usage?range=7d')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_requests', 25)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'requests_today',
                    'avg_response_ms',
                    'error_rate',
                    'by_endpoint',
                    'by_hour',
                ],
            ]);

        $topUsersResponse = $this->actingAs($this->admin)
            ->getJson('/api/admin/analytics/top-users?range=7d')
            ->assertOk()
            ->assertJsonPath('success', true);

        $topUsers = collect($topUsersResponse->json('data'));
        $consumerEntry = $topUsers->firstWhere('email', 'consumer@example.com');

        $this->assertNotNull($consumerEntry);
        $this->assertSame(1, $consumerEntry['request_count']);
    }

    public function test_admin_dashboard_stats_uses_schema_specific_timestamp_columns(): void
    {
        Cache::flush();

        $song = Song::factory()->create([
            'status' => 'published',
        ]);

        PlayHistory::query()->create([
            'user_id' => $this->admin->id,
            'song_id' => $song->id,
            'artist_id' => $song->artist_id,
            'played_at' => now(),
            'duration_played_seconds' => 180,
            'duration_played' => 180,
            'completed' => true,
            'skipped' => false,
            'completion_percentage' => 100,
        ]);

        Download::query()->create([
            'user_id' => $this->admin->id,
            'downloadable_type' => Song::class,
            'downloadable_id' => $song->id,
            'quality' => '320',
            'downloaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.activity.plays_today', 1)
            ->assertJsonPath('data.activity.downloads_today', 1);
    }

    public function test_admin_can_list_audit_logs_using_frontend_shape(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->admin->id,
            'action' => 'create_song',
            'auditable_type' => \App\Models\Song::class,
            'auditable_id' => 42,
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'published'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'url' => '/api/admin/songs/42',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.resource_type', 'song')
            ->assertJsonPath('data.0.resource_id', 42)
            ->assertJsonPath('data.0.changes.status.old', 'draft')
            ->assertJsonPath('data.0.changes.status.new', 'published')
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'total',
                    'per_page',
                ],
            ]);
    }

    public function test_admin_can_create_update_and_delete_feature_flags(): void
    {
        $createResponse = $this->actingAs($this->admin)
            ->postJson('/api/admin/feature-flags', [
                'key' => 'new_player_ui',
                'name' => 'New Player UI',
                'description' => 'Controls the upgraded player shell.',
                'enabled' => true,
                'rollout_percentage' => 75,
                'environments' => ['production', 'staging'],
                'conditions' => [
                    'user_roles' => ['admin'],
                ],
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.key', 'new_player_ui')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.rollout_percentage', 75);

        $flagId = (int) $createResponse->json('data.id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/feature-flags/{$flagId}", [
                'enabled' => false,
                'rollout_percentage' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.rollout_percentage', 100);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/feature-flags')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.key', 'new_player_ui');

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/feature-flags/{$flagId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('frontend_settings', [
            'id' => $flagId,
            'key' => 'new_player_ui',
        ]);

        $this->assertSame(0, DB::table('frontend_settings')->where('group', 'feature_flags')->count());
    }
}
