<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use DatabaseTransactions;

    private string $registerUrl = '/api/auth/register';

    protected function setUp(): void
    {
        parent::setUp();
        // Fake notifications so WelcomeNotification doesn't hit real mail/push
        Notification::fake();
    }

    private function validPayload(array $overrides = []): array
    {
        $unique = uniqid();

        return array_merge([
            'name' => 'Test User',
            'email' => "testuser_{$unique}@example.com",
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ], $overrides);
    }

    // ━━━ Successful Registration ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_user_can_register_with_valid_data(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertCreated()
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
                    'email' => $payload['email'],
                    'role' => 'user',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_register_creates_user_in_database(): void
    {
        $payload = $this->validPayload();

        $this->postJson($this->registerUrl, $payload)->assertCreated();

        // 'name' is mapped to 'display_name' via User::setNameAttribute mutator
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'display_name' => $payload['name'],
        ]);
    }

    public function test_register_creates_user_settings(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $userId = $response->json('data.id');
        $this->assertDatabaseHas('user_settings', [
            'user_id' => $userId,
        ]);
    }

    public function test_register_hashes_password(): void
    {
        $payload = $this->validPayload(['password' => 'MyPlainText!', 'password_confirmation' => 'MyPlainText!']);

        $this->postJson($this->registerUrl, $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('MyPlainText!', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('MyPlainText!', $user->password));
    }

    public function test_register_defaults_country_to_ug(): void
    {
        $payload = $this->validPayload();
        // Deliberately omit country

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertEquals('UG', $user->country);
    }

    public function test_register_accepts_optional_phone(): void
    {
        $payload = $this->validPayload(['phone' => '+256700123456']);

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertEquals('+256700123456', $user->phone);
    }

    public function test_register_accepts_optional_date_of_birth(): void
    {
        $payload = $this->validPayload(['date_of_birth' => '1995-06-15']);

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertNotNull($user->date_of_birth);
    }

    public function test_register_returns_json_content_type(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertCreated()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_register_returns_token_that_works(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $token = $response->json('token');

        // Use token to access /api/user
        $userResponse = $this->getJson('/api/user', [
            'Authorization' => "Bearer {$token}",
        ]);

        $userResponse->assertOk()
            ->assertJson(['data' => ['email' => $payload['email']]]);
    }

    // ━━━ Email Verification ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_register_does_not_auto_verify_email(): void
    {
        $payload = $this->validPayload();

        $this->postJson($this->registerUrl, $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertNull($user->email_verified_at, 'HIGH-6: email should NOT be auto-verified on registration');
    }

    // ━━━ Validation ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_register_requires_name(): void
    {
        $payload = $this->validPayload(['name' => '']);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_requires_email(): void
    {
        $payload = $this->validPayload(['email' => '']);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_valid_email(): void
    {
        $payload = $this->validPayload(['email' => 'not-an-email']);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_unique_email(): void
    {
        $existing = User::factory()->create();
        $payload = $this->validPayload(['email' => $existing->email]);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_password(): void
    {
        $payload = $this->validPayload(['password' => '', 'password_confirmation' => '']);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $payload = $this->validPayload([
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass!',
        ]);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_minimum_password_length(): void
    {
        $payload = $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_rejects_future_date_of_birth(): void
    {
        $payload = $this->validPayload(['date_of_birth' => '2030-01-01']);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_register_empty_body_returns_422(): void
    {
        $response = $this->postJson($this->registerUrl, []);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ]);
    }

    public function test_register_validation_returns_json_errors(): void
    {
        $response = $this->postJson($this->registerUrl, [
            'name' => '',
            'email' => 'invalid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ])
            ->assertHeader('Content-Type', 'application/json');
    }

    // ━━━ Security ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_register_does_not_return_password_hash(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_register_ignores_role_field(): void
    {
        $payload = $this->validPayload(['role' => 'admin']);

        $response = $this->postJson($this->registerUrl, $payload);

        if ($response->status() === 201) {
            // Role should be 'user', never 'admin' via registration
            $this->assertEquals('user', $response->json('data.role'));
        }
    }

    public function test_register_ignores_is_active_field(): void
    {
        $payload = $this->validPayload(['is_active' => false]);

        $response = $this->postJson($this->registerUrl, $payload);

        if ($response->status() === 201) {
            $user = User::where('email', $payload['email'])->first();
            // is_active should NOT be mass-assignable (HIGH-4 fix)
            $this->assertTrue((bool) $user->is_active);
        }
    }

    public function test_register_ignores_credits_field(): void
    {
        $payload = $this->validPayload(['credits' => 999999]);

        $response = $this->postJson($this->registerUrl, $payload);

        if ($response->status() === 201) {
            $user = User::where('email', $payload['email'])->first();
            $this->assertNotEquals(999999, $user->credits);
        }
    }

    public function test_register_does_not_redirect_returns_json(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);

        $this->assertNotEquals(302, $response->status());
        $content = $response->getContent();
        $this->assertStringNotContainsString('<!DOCTYPE', $content);
    }

    public function test_register_error_returns_json_not_html(): void
    {
        // Missing required fields should still return JSON, not HTML
        $response = $this->postJson($this->registerUrl, []);

        $content = $response->getContent();
        $this->assertStringNotContainsString('<!DOCTYPE', $content);
        $response->assertHeader('Content-Type', 'application/json');
    }

    // ━━━ CORS / Fetch Compatibility ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_register_endpoint_accepts_options_preflight(): void
    {
        $response = $this->call('OPTIONS', $this->registerUrl, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS-CONTROL-REQUEST-METHOD' => 'POST',
            'HTTP_ACCESS-CONTROL-REQUEST-HEADERS' => 'Content-Type, Accept',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
    }
}
