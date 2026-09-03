<?php

namespace Tests\Feature\Support;

use App\Helpers\FrontendUrl;
use App\Notifications\Concerns\BuildsFrontendUrls;
use Tests\TestCase;

/**
 * Links people open in a browser must point at the frontend.
 *
 * url() resolves against app.url — the API domain — so everything built with
 * it landed on api.tesotunes.com, which serves no pages. That shipped in
 * referral links and in the store order emails, sending customers to a dead
 * host to track a real order.
 *
 * One definition now, in FrontendUrl, with the notification trait delegating
 * to it so the two cannot drift apart.
 */
class FrontendUrlTest extends TestCase
{
    public function test_it_builds_against_the_configured_frontend(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', 'https://www.example.com');

        $this->assertSame('https://www.example.com/register?ref=ABCD', FrontendUrl::to('/register?ref=ABCD'));
    }

    public function test_it_never_builds_against_the_api_domain(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', 'https://www.example.com');

        $this->assertStringNotContainsString('api.example.com', FrontendUrl::to('/store/orders/1'));
    }

    /**
     * config()'s default only covers a missing key, not one explicitly null —
     * the first version of this fix produced a relative link because of it.
     */
    public function test_a_null_frontend_url_falls_back_by_stripping_the_api_host(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', null);

        $this->assertSame('https://example.com/join/somebody', FrontendUrl::to('/join/somebody'));
    }

    public function test_a_non_absolute_frontend_url_is_not_trusted(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', 'example.com');

        $this->assertSame('https://example.com/x', FrontendUrl::to('/x'));
    }

    public function test_it_tolerates_a_trailing_slash_and_a_missing_leading_slash(): void
    {
        config()->set('app.frontend_url', 'https://www.example.com/');

        $this->assertSame('https://www.example.com/a/b', FrontendUrl::to('a/b'));
        $this->assertStringNotContainsString('.com//', FrontendUrl::to('/a/b'));
    }

    public function test_a_local_port_survives_the_fallback(): void
    {
        config()->set('app.url', 'http://api.tesotunes.test:8080');
        config()->set('app.frontend_url', null);

        $this->assertSame('http://tesotunes.test:8080/x', FrontendUrl::to('/x'));
    }

    /** The trait the twenty notifications use must resolve identically. */
    public function test_the_notification_trait_delegates_to_the_same_definition(): void
    {
        config()->set('app.url', 'https://api.example.com');
        config()->set('app.frontend_url', 'https://www.example.com');

        $caller = new class
        {
            use BuildsFrontendUrls;

            public function link(string $path): string
            {
                return $this->frontendUrl($path);
            }
        };

        $this->assertSame(FrontendUrl::to('/store/orders/7'), $caller->link('/store/orders/7'));
    }
}
