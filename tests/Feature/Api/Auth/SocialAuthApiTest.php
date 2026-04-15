<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_social_exchange_rejects_unsupported_provider(): void
    {
        $response = $this->postJson('/api/auth/social/twitter/exchange', [
            'access_token' => 'token-1',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'UNSUPPORTED_PROVIDER');
    }

    public function test_social_exchange_requires_access_token_or_id_token(): void
    {
        $response = $this->postJson('/api/auth/social/google/exchange', []);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['errors' => ['access_token', 'id_token']]);
    }

    public function test_social_exchange_links_existing_user_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'linked@example.com',
            'provider' => null,
            'provider_id' => null,
            'email_verified_at' => null,
            'is_active' => true,
        ]);

        $this->mockSocialiteSuccess('google', 'social-google-id-1', 'linked@example.com');

        $response = $this->postJson('/api/auth/social/google/exchange', [
            'access_token' => 'provider-access-token',
            'device_name' => 'ios_app',
            'platform' => 'ios',
        ]);

        $response->assertOk()
            ->assertJsonPath('auth.provider', 'google')
            ->assertJsonPath('auth.linked_existing_email', true)
            ->assertJsonPath('auth.is_new_user', false)
            ->assertJsonPath('token_type', 'Bearer');

        $user->refresh();

        $this->assertSame('google', $user->provider);
        $this->assertSame('social-google-id-1', $user->provider_id);
        $this->assertNotNull($user->provider_token);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_social_exchange_rejects_invalid_provider_token(): void
    {
        $providerMock = Mockery::mock(Provider::class);
        $providerMock->shouldReceive('userFromToken')->once()->with('bad-token')->andThrow(new \Exception('bad token'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($providerMock);

        $response = $this->postJson('/api/auth/social/google/exchange', [
            'access_token' => 'bad-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'SOCIAL_TOKEN_INVALID');
    }

    public function test_social_exchange_blocks_suspended_accounts(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'provider' => 'google',
            'provider_id' => 'social-google-id-2',
            'is_active' => false,
        ]);

        $this->mockSocialiteSuccess('google', 'social-google-id-2', 'suspended@example.com');

        $response = $this->postJson('/api/auth/social/google/exchange', [
            'access_token' => 'valid-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
    }

    public function test_social_exchange_requires_provider_email_for_account_resolution(): void
    {
        $this->mockSocialiteSuccess('google', 'social-google-id-3', '');

        $response = $this->postJson('/api/auth/social/google/exchange', [
            'access_token' => 'valid-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'SOCIAL_EMAIL_REQUIRED');
    }

    private function mockSocialiteSuccess(string $provider, string $providerId, string $email): void
    {
        $socialUser = new class($providerId, $email)
        {
            public string $token = 'provider-token';

            public ?string $refreshToken = 'provider-refresh-token';

            public function __construct(private readonly string $providerId, private readonly string $email) {}

            public function getId(): string
            {
                return $this->providerId;
            }

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getName(): string
            {
                return 'Social User';
            }

            public function getAvatar(): string
            {
                return 'https://example.com/avatar.jpg';
            }
        };

        $providerMock = Mockery::mock(Provider::class);
        $providerMock->shouldReceive('userFromToken')->once()->andReturn($socialUser);

        Socialite::shouldReceive('driver')->once()->with($provider)->andReturn($providerMock);
    }
}
