<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private string $forgotUrl = '/api/auth/forgot-password';

    private string $resetUrl = '/api/auth/reset-password';

    // ━━━ Forgot Password ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_forgot_password_sends_reset_link_for_valid_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['is_active' => true]);

        $response = $this->postJson($this->forgotUrl, [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);
    }

    public function test_forgot_password_returns_422_for_unknown_email(): void
    {
        Notification::fake();

        $response = $this->postJson($this->forgotUrl, [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_forgot_password_validates_email_required(): void
    {
        $response = $this->postJson($this->forgotUrl, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $response = $this->postJson($this->forgotUrl, [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ━━━ Reset Password ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
            'is_active' => true,
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson($this->resetUrl, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);

        // Verify the password was actually changed
        $this->assertTrue(Hash::check('NewSecurePass456!', $user->fresh()->password));
    }

    public function test_reset_password_revokes_all_tokens(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
            'is_active' => true,
        ]);

        // Create some tokens
        $user->createToken('device-1');
        $user->createToken('device-2');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        $token = Password::createToken($user);

        $this->postJson($this->resetUrl, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ])->assertOk();

        // All tokens should be revoked after password reset
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_reset_password_with_invalid_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->postJson($this->resetUrl, [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_reset_password_requires_confirmation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = Password::createToken($user);

        $response = $this->postJson($this->resetUrl, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            // Missing password_confirmation
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_validates_all_fields_required(): void
    {
        $response = $this->postJson($this->resetUrl, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }

    public function test_reset_password_rejects_mismatched_confirmation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = Password::createToken($user);

        $response = $this->postJson($this->resetUrl, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'DifferentPassword789!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_rejects_wrong_email(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = Password::createToken($user);

        $response = $this->postJson($this->resetUrl, [
            'token' => $token,
            'email' => 'wrong@example.com',
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ]);

        $response->assertStatus(422);
    }
}
