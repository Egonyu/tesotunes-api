<?php

namespace App\Notifications\Concerns;

trait BuildsFrontendUrls
{
    protected function frontendUrl(string $path): string
    {
        $raw = rtrim((string) config('app.frontend_url', ''), '/');
        $parts = parse_url($raw);

        $validFrontendUrl = is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);

        $base = $validFrontendUrl
            ? $raw
            : $this->derivedFrontendBaseUrl();

        return $base.'/'.ltrim($path, '/');
    }

    protected function derivedFrontendBaseUrl(): string
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($appUrl);

        $validAppUrl = is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);

        if (! $validAppUrl) {
            return 'https://tesotunes.com';
        }

        $host = (string) $parts['host'];
        if (str_starts_with($host, 'api.')) {
            $host = substr($host, 4);
        }

        $base = strtolower((string) $parts['scheme']).'://'.$host;

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }
}
