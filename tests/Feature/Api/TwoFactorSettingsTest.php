<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Security\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class TwoFactorSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('user_security_profiles') && ! Schema::hasColumn('user_security_profiles', 'two_factor_confirmed_at')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_03_13_190000_add_two_factor_confirmed_at_to_user_security_tables.php',
                '--realpath' => false,
                '--force' => true,
            ]);
        }
    }

    public function test_two_factor_status_returns_disabled_state_by_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/settings/2fa')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'enabled' => false,
                    'recovery_codes_remaining' => 0,
                ],
            ]);
    }

    public function test_enable_returns_secret_qr_and_recovery_codes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/enable')
            ->assertOk();

        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_code_url'));
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_verify_confirms_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/settings/2fa/enable')->assertOk();

        $user->refresh();
        $code = $this->currentTotpFor($user->two_factor_secret);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/verify', ['code' => $code])
            ->assertOk();

        $this->assertCount(8, $response->json('data.recovery_codes'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_enabled' => true,
        ]);
        $this->assertDatabaseHas('user_security_profiles', [
            'user_id' => $user->id,
            'two_factor_enabled' => true,
        ]);
    }

    public function test_regenerate_recovery_codes_requires_confirmed_two_factor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/recovery-codes')
            ->assertStatus(409);
    }

    public function test_regenerate_recovery_codes_returns_new_codes_for_confirmed_two_factor(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/settings/2fa/enable')->assertOk();
        $user->refresh();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/verify', ['code' => $this->currentTotpFor($user->two_factor_secret)])
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/recovery-codes')
            ->assertOk();

        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_disable_two_factor_requires_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'two_factor_enabled' => true,
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWX234567AB',
            'two_factor_recovery_codes' => json_encode(['CODE123456']),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->syncSecurityProfile();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/disable', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings/2fa/disable', ['password' => 'SecurePass123!'])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
    }

    private function currentTotpFor(string $secret): string
    {
        $service = app(TwoFactorService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('generateTotp');
        $method->setAccessible(true);

        return $method->invoke($service, $secret, (int) floor(time() / 30));
    }
}
