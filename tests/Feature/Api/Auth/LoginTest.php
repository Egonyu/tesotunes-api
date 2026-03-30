<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use DatabaseTransactions;

    private string $loginUrl = '/api/auth/login';

    private function postLoginFromIp(array $payload, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson($this->loginUrl, $payload);
    }

    // ━━━ Successful Login ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'SecurePass123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'email', 'role',
                    'is_active', 'is_verified', 'is_premium',
                ],
                'token',
                'token_type',
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'data' => [
                    'email' => $user->email,
                ],
            ]);

        // Token must be a non-empty string
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_returns_user_resource_with_settings(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('avatar', $data);
        $this->assertArrayHasKey('role', $data);
        $this->assertArrayHasKey('country', $data);
        $this->assertArrayHasKey('social_links', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function test_login_updates_last_login_at(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
            'last_login_at' => null,
        ]);

        $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_login_with_remember_me_returns_token(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
            'remember_me' => true,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_local_admin_login_allows_admin_without_verified_email(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
            'role' => 'admin',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/auth/local-admin-login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'admin');

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_succeeds_when_optional_profile_tables_are_missing(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->andReturnUsing(function (string $table): bool {
                return ! in_array($table, ['user_profiles', 'user_referrals', 'user_subscriptions'], true);
            });

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_login_returns_json_content_type(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    // ━━━ Invalid Credentials ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Invalid credentials']);

        // Must NOT leak a token
        $this->assertNull($response->json('token'));
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => 'nonexistent@example.com',
            'password' => 'anything',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_login_is_rate_limited_after_configured_max_attempts(): void
    {
        Setting::set('auth_max_login_attempts', 2, Setting::TYPE_INTEGER, Setting::GROUP_SECURITY);

        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $ipAddress = '198.51.100.10';
        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];

        $this->postLoginFromIp($payload, $ipAddress)->assertUnauthorized();
        $this->postLoginFromIp($payload, $ipAddress)->assertUnauthorized();

        $this->postLoginFromIp($payload, $ipAddress)
            ->assertTooManyRequests()
            ->assertJsonStructure(['message']);
    }

    public function test_login_fails_with_case_sensitive_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123', // lowercase p
        ]);

        $response->assertUnauthorized();
    }

    // ━━━ Account Status ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertForbidden()
            ->assertJson(['message' => 'Account is suspended']);
    }

    // ━━━ Validation ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_login_requires_email(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_password(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => 'user@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_requires_valid_email_format(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_empty_body_returns_422(): void
    {
        $response = $this->postJson($this->loginUrl, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_validation_returns_json_errors(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => '',
            'password' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['email', 'password'],
            ]);
    }

    // ━━━ Security ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_login_does_not_return_password_hash(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_login_does_not_redirect_returns_json(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $this->assertNotEquals(302, $response->status());
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_login_error_does_not_redirect(): void
    {
        $response = $this->postJson($this->loginUrl, [
            'email' => 'wrong@example.com',
            'password' => 'wrong',
        ]);

        $this->assertNotEquals(302, $response->status());
        $content = $response->getContent();
        $this->assertStringNotContainsString('<!DOCTYPE', $content);
    }

    // ━━━ Token Validation (authenticated endpoints) ━━━━━━━━━━━━━

    public function test_token_from_login_works_for_auth_endpoints(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('token');

        // Use the token to access /api/auth/user
        $userResponse = $this->getJson('/api/auth/user', [
            'Authorization' => "Bearer {$token}",
        ]);

        $userResponse->assertOk()
            ->assertJson([
                'data' => ['email' => $user->email],
            ]);
    }

    public function test_logout_invalidates_token(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('token');

        // Logout
        $this->postJson('/api/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        // Refresh app to clear cached auth state
        $this->refreshApplication();

        // Token should be invalid now
        $this->getJson('/api/auth/user', [
            'Authorization' => "Bearer {$token}",
        ])->assertUnauthorized();
    }

    // ━━━ CORS / Fetch Compatibility ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_login_endpoint_allows_json_content_type(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson($this->loginUrl, [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        // Should NOT return 415 Unsupported Media Type
        $this->assertNotEquals(415, $response->status());
        $response->assertOk();
    }

    public function test_login_endpoint_accepts_options_preflight(): void
    {
        $response = $this->call('OPTIONS', $this->loginUrl, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS-CONTROL-REQUEST-METHOD' => 'POST',
            'HTTP_ACCESS-CONTROL-REQUEST-HEADERS' => 'Content-Type, Accept',
        ]);

        // OPTIONS should return 200 or 204 for CORS preflight
        $this->assertContains($response->getStatusCode(), [200, 204]);
    }
}
