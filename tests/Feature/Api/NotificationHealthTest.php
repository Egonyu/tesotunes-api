<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_view_notification_health(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN, $admin->id);
        $admin->clearPermissionCache();

        $response = $this->actingAs($admin)->getJson('/api/notifications/health');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'mail' => [
                        'mailer',
                        'from_address_configured',
                        'smtp_host_configured',
                        'smtp_port_configured',
                        'is_log_mailer',
                        'is_array_mailer',
                    ],
                    'queue' => [
                        'connection',
                        'is_async',
                        'pending_jobs',
                        'failed_jobs',
                        'recent_failures',
                    ],
                    'push' => [
                        'active_device_tokens',
                    ],
                    'notifications' => [
                        'sent_last_24h',
                        'unread_total',
                        'top_types_last_7d',
                    ],
                    'checks' => [
                        'mail_ready',
                        'queue_ready',
                        'push_ready',
                    ],
                ],
            ]);
    }

    public function test_regular_user_cannot_view_notification_health(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/notifications/health')
            ->assertForbidden();
    }
}
