<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use DatabaseTransactions;

    private string $registerUrl = '/api/auth/register';

    private function postRegisterFromIp(array $payload, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson($this->registerUrl, $payload);
    }

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
                'message',
                'data' => [
                    'id', 'name', 'email', 'role',
                    'is_active', 'is_verified', 'is_premium',
                ],
                'requires_email_verification',
            ])
            ->assertJson([
                'message' => 'Registration successful. Please verify your email before signing in.',
                'requires_email_verification' => true,
                'data' => [
                    'email' => $payload['email'],
                    'role' => 'user',
                ],
            ]);
        Notification::assertSentTo(
            User::where('email', $payload['email'])->firstOrFail(),
            VerifyEmailNotification::class
        );
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

    public function test_register_is_rate_limited_after_repeated_attempts_for_same_email_and_ip(): void
    {
        $ipAddress = '198.51.100.20';
        $payload = $this->validPayload(['email' => 'rate-limit@example.com']);

        $this->postRegisterFromIp($payload, $ipAddress)->assertCreated();

        for ($attempt = 2; $attempt <= 10; $attempt++) {
            $this->postRegisterFromIp($payload, $ipAddress)->assertUnprocessable();
        }

        $this->postRegisterFromIp($payload, $ipAddress)
            ->assertTooManyRequests()
            ->assertJsonStructure(['message']);
    }

    public function test_register_hashes_password(): void
    {
        $payload = $this->validPayload(['password' => 'MyPlainText1!', 'password_confirmation' => 'MyPlainText1!']);

        $this->postJson($this->registerUrl, $payload)->assertCreated();

        $user = User::where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('MyPlainText1!', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('MyPlainText1!', $user->password));
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

    public function test_register_does_not_issue_a_token_before_email_verification(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson($this->registerUrl, $payload);
        $response->assertCreated();

        $this->assertNull($response->json('token'));
        $this->assertTrue((bool) $response->json('requires_email_verification'));
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

    public function test_register_rejects_soft_deleted_email_without_destroying_the_account(): void
    {
        $deleted = User::factory()->create();
        $deletedId = $deleted->id;
        $deleted->delete();

        $payload = $this->validPayload(['email' => $deleted->email]);

        $response = $this->postJson($this->registerUrl, $payload);

        $response->assertConflict()
            ->assertJson(['code' => 'ACCOUNT_PREVIOUSLY_DELETED']);

        $this->assertNotNull(
            User::onlyTrashed()->find($deletedId),
            'Soft-deleted account must never be force-deleted by a registration attempt'
        );
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
