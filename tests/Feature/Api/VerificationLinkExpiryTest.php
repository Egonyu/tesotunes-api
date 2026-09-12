<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How long a verification link lives.
 *
 * Laravel's 60-minute default is tight on a connection that may not carry email
 * through for hours: the link dies before it is read. The window is now
 * configurable and set to 12 hours.
 *
 * The email body states the figure, so these also pin the two together — a
 * window that disagrees with the sentence promising it is its own bug.
 */
class VerificationLinkExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function urlFor(User $user): string
    {
        return (new VerifyEmailNotification)->toMail($user)->actionUrl;
    }

    public function test_the_link_is_valid_for_twelve_hours_by_default(): void
    {
        $user = User::factory()->unverified()->create();

        parse_str((string) parse_url($this->urlFor($user), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('expires', $query);
        $this->assertEqualsWithDelta(
            now()->addHours(12)->timestamp,
            (int) $query['expires'],
            60,
            'the signed link should last 12 hours'
        );
    }

    public function test_the_window_follows_the_configured_value(): void
    {
        config(['auth.verification.expire' => 90]);
        $user = User::factory()->unverified()->create();

        parse_str((string) parse_url($this->urlFor($user), PHP_URL_QUERY), $query);

        $this->assertEqualsWithDelta(now()->addMinutes(90)->timestamp, (int) $query['expires'], 60);
    }

    public function test_the_email_states_the_window_it_actually_grants(): void
    {
        $user = User::factory()->unverified()->create();

        $body = implode(' ', (new VerifyEmailNotification)->toMail($user)->introLines);
        $body .= ' '.implode(' ', (new VerifyEmailNotification)->toMail($user)->outroLines);

        $this->assertStringContainsString('12 hours', $body);
        $this->assertStringNotContainsString('60 minutes', $body);
    }

    public function test_the_stated_window_tracks_the_configuration(): void
    {
        // The sentence is derived from the same value as the signature, so a
        // change to one cannot leave the other lying to the reader.
        config(['auth.verification.expire' => 60]);
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmailNotification)->toMail($user);
        $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('1 hour', $body);
    }

    public function test_a_sub_hour_window_is_stated_in_minutes(): void
    {
        config(['auth.verification.expire' => 45]);
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmailNotification)->toMail($user);
        $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('45 minutes', $body);
    }
}
