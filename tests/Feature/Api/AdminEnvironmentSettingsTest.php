<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\EnvironmentSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminEnvironmentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );
        Role::query()->firstOrCreate(
            ['name' => 'super_admin'],
            ['display_name' => 'Super Admin', 'description' => 'Super Administrator', 'is_active' => true, 'priority' => 10]
        );

        $this->envPath = storage_path('framework/testing/admin-environment-settings.env');
        File::ensureDirectoryExists(dirname($this->envPath));
        File::put($this->envPath, implode("\n", [
            'APP_NAME=OldName',
            'APP_DEBUG=false',
            'MAIL_PASSWORD=secret123',
            'GOOGLE_CLIENT_ID=old-google-client-id',
            'GOOGLE_CLIENT_SECRET=old-google-secret',
            'FACEBOOK_CLIENT_ID=old-facebook-client-id',
            'FACEBOOK_CLIENT_SECRET=old-facebook-secret',
            'QUEUE_CONNECTION=database',
            '',
        ]));

        $this->app->instance(EnvironmentSettingsService::class, new EnvironmentSettingsService($this->envPath));
    }

    protected function tearDown(): void
    {
        if (File::exists($this->envPath)) {
            File::delete($this->envPath);
        }

        parent::tearDown();
    }

    public function test_super_admin_can_view_environment_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin', $user->id);
        $user->clearPermissionCache();

        $response = $this->actingAs($user)->getJson('/api/admin/settings/environment');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.scope', 'api')
            ->assertJsonPath('data.file', '.env')
            ->assertJsonPath('data.groups.0.id', 'application');

        $mailPasswordField = collect($response->json('data.groups'))
            ->flatMap(fn (array $group) => $group['fields'])
            ->firstWhere('key', 'MAIL_PASSWORD');

        $googleClientSecretField = collect($response->json('data.groups'))
            ->flatMap(fn (array $group) => $group['fields'])
            ->firstWhere('key', 'GOOGLE_CLIENT_SECRET');

        $facebookClientSecretField = collect($response->json('data.groups'))
            ->flatMap(fn (array $group) => $group['fields'])
            ->firstWhere('key', 'FACEBOOK_CLIENT_SECRET');

        $this->assertNotNull($mailPasswordField);
        $this->assertTrue($mailPasswordField['secret']);
        $this->assertNull($mailPasswordField['value']);
        $this->assertTrue($mailPasswordField['configured']);

        $this->assertNotNull($googleClientSecretField);
        $this->assertTrue($googleClientSecretField['secret']);
        $this->assertNull($googleClientSecretField['value']);
        $this->assertTrue($googleClientSecretField['configured']);

        $this->assertNotNull($facebookClientSecretField);
        $this->assertTrue($facebookClientSecretField['secret']);
        $this->assertNull($facebookClientSecretField['value']);
        $this->assertTrue($facebookClientSecretField['configured']);
    }

    public function test_super_admin_can_update_environment_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin', $user->id);
        $user->clearPermissionCache();

        $this->actingAs($user)->putJson('/api/admin/settings/environment', [
            'values' => [
                'APP_NAME' => 'New Platform Name',
                'APP_DEBUG' => true,
                'MAIL_PASSWORD' => 'rotated-secret',
                'GOOGLE_CLIENT_ID' => 'new-google-client-id',
                'GOOGLE_CLIENT_SECRET' => 'new-google-secret',
                'FACEBOOK_CLIENT_ID' => 'new-facebook-client-id',
                'FACEBOOK_CLIENT_SECRET' => 'new-facebook-secret',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.restart_required', true);

        $contents = File::get($this->envPath);

        $this->assertStringContainsString('APP_NAME="New Platform Name"', $contents);
        $this->assertStringContainsString('APP_DEBUG=true', $contents);
        $this->assertStringContainsString('MAIL_PASSWORD=rotated-secret', $contents);
        $this->assertStringContainsString('GOOGLE_CLIENT_ID=new-google-client-id', $contents);
        $this->assertStringContainsString('GOOGLE_CLIENT_SECRET=new-google-secret', $contents);
        $this->assertStringContainsString('FACEBOOK_CLIENT_ID=new-facebook-client-id', $contents);
        $this->assertStringContainsString('FACEBOOK_CLIENT_SECRET=new-facebook-secret', $contents);
    }

    public function test_admin_cannot_manage_environment_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin', $user->id);
        $user->clearPermissionCache();

        $this->actingAs($user)
            ->getJson('/api/admin/settings/environment')
            ->assertForbidden();
    }
}
