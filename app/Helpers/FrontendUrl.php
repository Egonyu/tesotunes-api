<?php

namespace App\Helpers;

/**
 * Builds links to pages people open in a browser.
 *
 * url() resolves against app.url, which is the API domain — so anything built
 * with it points at api.tesotunes.com, where no page exists. Referral links
 * shipped that way and every one of them was a 404; the store order emails had
 * the same fault, sending customers to a dead host to track a real order.
 *
 * The logic lived in a trait under App\Notifications\Concerns, which the
 * notifications used and nothing else could reach without inheriting a
 * namespace it had no business in. It lives here now, beside StorageHelper,
 * which does the same job for media. The trait remains as sugar for
 * notifications and delegates here, so there is one definition rather than
 * two that drift.
 */
class FrontendUrl
{
    /**
     * An absolute URL to a page on the frontend.
     */
    public static function to(string $path): string
    {
        return static::base().'/'.ltrim($path, '/');
    }

    /**
     * The frontend's base URL.
     *
     * Prefers app.frontend_url. Falls back to app.url with a leading `api.`
     * stripped, which is right for this deployment (api.tesotunes.com ->
     * tesotunes.com) and harmless where the two already match.
     */
    public static function base(): string
    {
        $configured = rtrim((string) config('app.frontend_url', ''), '/');

        return static::isAbsolute($configured)
            ? $configured
            : static::derivedFromAppUrl();
    }

    private static function derivedFromAppUrl(): string
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');

        if (! static::isAbsolute($appUrl)) {
            return 'https://tesotunes.com';
        }

        $parts = parse_url($appUrl);
        $host = (string) ($parts['host'] ?? '');

        if (str_starts_with($host, 'api.')) {
            $host = substr($host, 4);
        }

        $base = strtolower((string) $parts['scheme']).'://'.$host;

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }

    /**
     * Whether a string is a usable absolute http(s) URL.
     *
     * A configured value of null, an empty string, or a bare host is not
     * something to build a link on — config()'s default only covers a missing
     * key, not one explicitly set to null.
     */
    private static function isAbsolute(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
    }
}
