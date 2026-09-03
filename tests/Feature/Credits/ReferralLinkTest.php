<?php

namespace Tests\Feature\Credits;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A referral link has to open the registration page.
 *
 * These were built with url(), which resolves against app.url — the API
 * domain — so every link produced pointed at api.tesotunes.com/register. That
 * is a 404: the registration page is served by the frontend. A referral link
 * that goes nowhere is worse than none at all, because it looks like it works
 * right up until somebody clicks it.
 */
class ReferralLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_referral_link_points_at_the_frontend_not_the_api(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', 'https://www.example.com');

        $user = User::factory()->create();
        $user->generateReferralCode();

        $link = $user->referral_link;

        $this->assertStringStartsWith('https://www.example.com/register?ref=', $link);
        $this->assertStringNotContainsString('api.example.com', $link);
    }

    public function test_the_link_carries_the_account_s_own_code(): void
    {
        config()->set('app.frontend_url', 'https://www.example.com');

        $user = User::factory()->create();
        $code = $user->generateReferralCode();

        $this->assertSame("https://www.example.com/register?ref={$code}", $user->fresh()->referral_link);
    }

    /** A trailing slash in config must not produce a double slash in the link. */
    public function test_a_trailing_slash_in_the_configured_url_is_tolerated(): void
    {
        config()->set('app.frontend_url', 'https://www.example.com/');

        $user = User::factory()->create();
        $user->generateReferralCode();

        $this->assertStringNotContainsString('.com//register', $user->referral_link);
    }

    /** With no frontend configured, fall back rather than produce a broken link. */
    public function test_it_falls_back_to_app_url_when_no_frontend_is_configured(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', null);

        $user = User::factory()->create();
        $user->generateReferralCode();

        $this->assertStringStartsWith('https://api.example.com/register?ref=', $user->referral_link);
    }
}
