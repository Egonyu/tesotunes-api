<?php

namespace Tests\Feature\Api\Security;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthObservabilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_protected_auth_route_returns_standardized_unauthenticated_json(): void
    {
        $this->getJson('/api/auth/user')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_login_rate_limit_returns_standardized_json_payload(): void
    {
        Setting::set('auth_max_login_attempts', 1, Setting::TYPE_INTEGER, Setting::GROUP_SECURITY);

        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $ipAddress = '198.51.100.25';
        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/auth/login', $payload)
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertJson([
                'success' => false,
                'message' => 'Too many attempts. Please try again later.',
            ])
            ->assertJsonStructure(['retry_after']);
    }
}
