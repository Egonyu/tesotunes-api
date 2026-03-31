<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config()->set('app.frontend_url', 'http://localhost:3000');
    }

    public function test_public_resend_sends_verification_email_for_unverified_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/auth/email/resend', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJson([
                'message' => 'If your account still requires verification, we have sent a fresh verification email.',
            ]);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verification_notification_points_to_frontend_verify_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $notification = new VerifyEmailNotification;
        $mailMessage = $notification->toMail($user);

        $this->assertIsString($mailMessage->actionUrl);
        $this->assertStringStartsWith('http://localhost:3000/verify-email?', $mailMessage->actionUrl);
        $this->assertStringContainsString('id='.$user->id, $mailMessage->actionUrl);
        $this->assertStringContainsString('hash='.sha1($user->getEmailForVerification()), $mailMessage->actionUrl);
        $this->assertStringContainsString('expires=', $mailMessage->actionUrl);
        $this->assertStringContainsString('signature=', $mailMessage->actionUrl);
    }

    public function test_public_resend_returns_generic_success_for_unknown_email(): void
    {
        $this->postJson('/api/auth/email/resend', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJson([
                'message' => 'If your account still requires verification, we have sent a fresh verification email.',
            ]);
    }

    public function test_post_verify_accepts_a_valid_signed_payload(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        parse_str((string) parse_url($verificationUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/email/verify', [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ])->assertOk()
            ->assertJson([
                'message' => 'Email verified successfully.',
            ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_signed_verification_link_redirects_to_frontend_success_state(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->get($verificationUrl)
            ->assertRedirect('http://localhost:3000/verify-email?status=verified');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
