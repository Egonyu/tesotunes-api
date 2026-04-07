<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHomepageThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );
    }

    public function test_admin_can_switch_homepage_theme_globally(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin', $user->id);
        $user->clearPermissionCache();

        $this->actingAs($user)
            ->putJson('/api/admin/settings', [
                'appearance' => [
                    'homepage_theme' => 'curated_home',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('curated_home', Setting::get('appearance_homepage_theme'));

        $this->getJson('/api/platform-settings')
            ->assertOk()
            ->assertJsonPath('data.appearance.homepage_theme', 'curated_home');
    }

    public function test_invalid_homepage_theme_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin', $user->id);
        $user->clearPermissionCache();

        $this->actingAs($user)
            ->putJson('/api/admin/settings', [
                'appearance' => [
                    'homepage_theme' => 'experimental',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
