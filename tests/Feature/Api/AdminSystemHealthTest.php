<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminSystemHealthTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUserWithRole('admin');
    }

    public function test_admin_can_fetch_system_health_snapshot(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/system/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'overall_score',
                    'status',
                    'deployment',
                    'components',
                    'backup',
                    'alerts',
                    'recommendations',
                    'timestamp',
                ],
            ]);
    }

    public function test_admin_can_run_safe_system_action(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/system/actions', [
            'command' => 'queue:restart',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }
}
