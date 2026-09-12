<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private string $forgotUrl = '/api/auth/forgot-password';

    private string $resetUrl = '/api/auth/reset-password';

    private function createActiveUser(array $attributes = []): User
    {
        $suffix = (string) Str::uuid();

        return User::factory()->create([
            'username' => 'password-reset-'.Str::lower($suffix),
            'email' => 'password-reset-'.$suffix.'@example.com',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    // ━━━ Forgot Password ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_forgot_password_sends_reset_link_for_valid_email(): void
    {
        Notification::fake();

        $user = $this->createActiveUser();

        $response = $this->postJson($this->forgotUrl, [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);
    }

    /**
     * This previously asserted a 422 for an unknown address, which encoded an
     * account checker as the expected behaviour: anyone could confirm which
     * emails have accounts here by reading the status code. The endpoint now
     * answers the same way either way, so the assertion is inverted.
     */
    public function test_forgot_password_does_not_reveal_whether_an_email_is_registered(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson($this->forgotUrl, ['email' => $user->email]);
        $unknown = $this->postJson($this->forgotUrl, ['email' => 'nonexistent@example.com']);

        $known->assertSuccessful();
        $unknown->assertSuccessful();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
        Notification::assertSentTimes(\Illuminate\Auth\Notifications\ResetPassword::class, 1);
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
        $user = $this->createActiveUser([
            'password' => Hash::make('OldPassword123!'),
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
        $user = $this->createActiveUser([
            'password' => Hash::make('OldPassword123!'),
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
        $user = $this->createActiveUser();

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
        $user = $this->createActiveUser();
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
        $user = $this->createActiveUser();
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
        $user = $this->createActiveUser();
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
