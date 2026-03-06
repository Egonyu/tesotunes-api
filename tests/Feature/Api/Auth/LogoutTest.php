<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use DatabaseTransactions;

    private string $loginUrl = '/api/auth/login';

    private string $logoutUrl = '/api/auth/logout';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function loginAndGetToken(User $user, string $password = 'SecurePass123!'): string
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => $password,
        ]);

        return $response->json('token');
    }

    // ━━━ Successful Logout ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        $token = $this->loginAndGetToken($user);

        $response = $this->postJson($this->logoutUrl, [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);
    }

    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        $token = $this->loginAndGetToken($user);

        $this->postJson($this->logoutUrl, [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        // Clear cached auth state without losing DB transaction
        $this->app['auth']->forgetGuards();

        // Token should be invalid now
        $this->getJson('/api/auth/user', [
            'Authorization' => "Bearer {$token}",
        ])->assertUnauthorized();
    }

    public function test_logout_does_not_delete_other_tokens(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        // Login twice to get two tokens
        $token1 = $this->loginAndGetToken($user);
        $token2 = $this->loginAndGetToken($user);

        // Logout with token1
        $this->postJson($this->logoutUrl, [], [
            'Authorization' => "Bearer {$token1}",
        ])->assertOk();

        // Clear cached auth state without losing DB transaction
        $this->app['auth']->forgetGuards();

        // token1 should be invalid
        $this->getJson('/api/auth/user', [
            'Authorization' => "Bearer {$token1}",
        ])->assertUnauthorized();

        // Clear cached auth state again before testing token2
        $this->app['auth']->forgetGuards();

        // token2 should still work
        $this->getJson('/api/auth/user', [
            'Authorization' => "Bearer {$token2}",
        ])->assertOk();
    }

    // ━━━ Unauthenticated ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson($this->logoutUrl);

        $response->assertUnauthorized();
    }

    public function test_logout_with_invalid_token_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-string')
            ->postJson($this->logoutUrl);

        $response->assertUnauthorized();
    }

    // ━━━ HTTP Method ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_logout_rejects_get_method(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        $token = $this->loginAndGetToken($user);

        $response = $this->getJson($this->logoutUrl, [
            'Authorization' => "Bearer {$token}",
        ]);

        // Laravel may return 405 (Method Not Allowed) or 404 depending on route configuration
        $this->assertTrue(
            in_array($response->status(), [404, 405]),
            "Expected 404 or 405, got {$response->status()}"
        );
    }
}
