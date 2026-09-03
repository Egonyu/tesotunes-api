<?php

namespace App\Notifications\Concerns;

use App\Helpers\FrontendUrl;

/**
 * Frontend links for notifications.
 *
 * The logic moved to {@see FrontendUrl} so models, controllers and services
 * could use it too — a trait under App\Notifications was not something they
 * could reach without inheriting a namespace they had no business in, and the
 * result was fourteen files building links with url() against the API domain.
 *
 * This stays as sugar for the twenty notifications already calling
 * $this->frontendUrl(), and delegates rather than repeating itself.
 */
trait BuildsFrontendUrls
{
    protected function frontendUrl(string $path): string
    {
        return FrontendUrl::to($path);
    }

    protected function derivedFrontendBaseUrl(): string
    {
        return FrontendUrl::base();
    }
}
