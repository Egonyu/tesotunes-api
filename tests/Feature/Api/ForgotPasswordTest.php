<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The password reset link itself.
 *
 * Laravel's ResetPassword notification defaults to route('password.reset') — a
 * web route this API-only application never defined — so building the mail threw
 * RouteNotFoundException while handling the request. Forgot-password returned a
 * 500, nothing reached the queue, and nothing appeared in failed_jobs. No mail
 * provider fix would have helped: the link could not be generated at all.
 *
 * Tests\Feature\Api\Auth\PasswordResetTest covers the endpoints either side of
 * this; these cover only the URL, which is what was broken.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_link_can_be_built_at_all(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPassword('token-123'))->toMail($user);

        $this->assertNotNull($mail->actionUrl);
    }

    public function test_the_reset_link_points_at_the_frontend_page_that_handles_it(): void
    {
        config(['app.frontend_url' => 'https://tesotunes.com']);
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $mail = (new ResetPassword('token-123'))->toMail($user);

        // The reset page reads ?token= and ?email= from the query string.
        $this->assertStringStartsWith('https://tesotunes.com/reset-password?', $mail->actionUrl);
        $this->assertStringContainsString('token=token-123', $mail->actionUrl);
        $this->assertStringContainsString('email=buyer%40example.com', $mail->actionUrl);
    }

    public function test_the_link_never_points_at_the_api_host(): void
    {
        // url() resolves against app.url, which is the API domain — a link built
        // that way sends people to a host with no reset page on it.
        config(['app.frontend_url' => 'https://tesotunes.com', 'app.url' => 'https://api.tesotunes.com']);
        $user = User::factory()->create();

        $mail = (new ResetPassword('token-123'))->toMail($user);

        $this->assertStringNotContainsString('api.tesotunes.com', $mail->actionUrl);
    }
}
