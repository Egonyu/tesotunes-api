<?php

namespace Tests\Feature\Contributions;

use App\Models\Role;
use App\Models\User;
use App\Modules\Contributions\Support\ContributionsModule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleToggleTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'priority' => 90]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_status_endpoint_is_public_and_reports_state(): void
    {
        ContributionsModule::setEnabled(true);

        $this->getJson('/api/contributions/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    public function test_admin_can_toggle_the_module_off_and_contributor_routes_503(): void
    {
        ContributionsModule::setEnabled(true);
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->putJson('/api/contributions/admin/settings', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        // A contributor-facing route is now gated off.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/contributions/consent')->assertStatus(503);
    }

    public function test_contributor_routes_work_when_enabled(): void
    {
        ContributionsModule::setEnabled(true);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/contributions/consent')->assertOk();
    }

    public function test_admin_settings_reachable_even_when_module_off(): void
    {
        ContributionsModule::setEnabled(false);

        Sanctum::actingAs($this->admin());
        // Admin console must stay reachable to flip the toggle back on.
        $this->getJson('/api/contributions/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }
}
