<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;

class SecurityAuditRoutes extends Command
{
    protected $signature = 'security:audit-routes {--fail-on-issues : Exit with non-zero code if issues found}';

    protected $description = 'Audit all routes for missing authentication/authorization middleware';

    /**
     * Routes that are intentionally public (no auth required)
     */
    private array $allowedPublicPrefixes = [
        'api/health',
        'api/songs',
        'api/artists',
        'api/albums',
        'api/genres',
        'api/trending',
        'api/playlists',
        'api/events',
        'api/podcasts',
        'api/episodes',
        'api/podcasts-search',
        'api/podcasts-trending',
        'api/podcast-categories',
        'api/feed',
        'api/slideshow',
        'api/mobile',
        'api/ads',
        'api/theme',
        'api/announcements',
        'api/v1',
        'api/webhooks',
        'api/store/webhooks',
        'api/content',
        'api/login',
        'api/register',
        'api/auth/login',
        'api/auth/register',
        'api/auth/forgot-password',
        'api/auth/reset-password',
        'api/auth/email/verify',
        'api/forgot-password',
        'api/reset-password',
        'api/email/verify',
        'auth/login',
        'auth/register',
        'up',
        'sanctum',
        'storage',
        'telescope',
        '_boost',
        // Web auth routes are handled separately by session-based auth
        'auth',
    ];

    /**
     * Routes that are allowed to only have auth:sanctum (no role check needed)
     */
    private array $allowedAuthOnlyPrefixes = [
        'api/player',
        'api/user',
        'api/like',
        'api/bookmark',
        'api/events/{id}/interest',
        'api/notifications',
        'api/device-tokens',
        'api/payments/subscription',
        'api/payouts',
        'api/subscriptions',
        'api/isrc',
        'api/songs',
        'api/albums',
        'api/distributions',
        'api/feed',
        'api/tickets',
        'api/sacco',
        'api/store',
    ];

    /**
     * Prefixes that MUST have admin role middleware
     */
    private array $requireAdminRole = [
        'api/admin',
    ];

    /**
     * Prefixes that MUST have artist role middleware
     * Note: api/artist/apply and api/artist/application-status are excluded
     * because users apply BEFORE having the artist role
     */
    private array $requireArtistRole = [
        'api/artist',
    ];

    /**
     * Specific routes exempted from role checks (e.g., artist application)
     */
    private array $roleExemptRoutes = [
        'api/artist/apply',
        'api/artist/application-status',
    ];

    /**
     * Custom middleware aliases that satisfy artist access requirements.
     */
    private array $artistAccessMiddlewareAliases = [
        'artist.events.access',
    ];

    /**
     * Public state-changing routes that are intentionally exposed for
     * guest flows such as ticket checkout.
     */
    private array $allowedPublicStateChangingRoutes = [
        'api/tickets/quote',
        'api/tickets/discounts/validate',
        'api/tickets/purchase',
        'api/events/{id}/funnel-touch',
        'api/auth/local-admin-login',
        'api/auth/email/resend',
        'api/auth/social/{provider}/exchange',
        // Polls support guest responses when allow_guests_respond=true on the poll;
        // auth is enforced at the application layer inside PollResponseController.
        'api/polls/{poll}/respond',
    ];

    public function handle(Router $router): int
    {
        $this->info('🔒 TesoTunes Route Security Audit');
        $this->info('================================');
        $this->newLine();

        $issues = [];
        $routes = $router->getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = implode('|', $route->methods());
            $middleware = $route->gatherMiddleware();
            $middlewareStr = implode(', ', $middleware);

            // Skip OPTIONS routes
            if ($methods === 'OPTIONS' || in_array('HEAD', $route->methods()) && count($route->methods()) === 2) {
                continue;
            }

            // Check if this is an intentionally public route
            if ($this->matchesPrefix($uri, $this->allowedPublicPrefixes)) {
                // Public routes for auth endpoints must still have throttle
                if (str_contains($uri, 'login') || str_contains($uri, 'register')) {
                    if (! $this->hasMiddleware($middleware, 'throttle') && ! $this->hasMiddleware($middleware, 'api.rate_limit')) {
                        $issues[] = [
                            'severity' => 'HIGH',
                            'uri' => $uri,
                            'methods' => $methods,
                            'issue' => 'Auth endpoint missing rate limiting (throttle middleware)',
                            'middleware' => $middlewareStr,
                        ];
                    }
                }

                continue;
            }

            if ($this->matchesExact($uri, $this->allowedPublicStateChangingRoutes)) {
                continue;
            }

            // Check admin routes MUST have auth + role
            if ($this->matchesPrefix($uri, $this->requireAdminRole)) {
                if (! $this->hasMiddleware($middleware, 'auth:sanctum')) {
                    $issues[] = [
                        'severity' => 'CRITICAL',
                        'uri' => $uri,
                        'methods' => $methods,
                        'issue' => 'Admin route MISSING auth:sanctum middleware',
                        'middleware' => $middlewareStr,
                    ];
                }
                if (! $this->hasMiddleware($middleware, 'role')) {
                    $issues[] = [
                        'severity' => 'CRITICAL',
                        'uri' => $uri,
                        'methods' => $methods,
                        'issue' => 'Admin route MISSING role middleware',
                        'middleware' => $middlewareStr,
                    ];
                }

                continue;
            }

            // Check artist routes MUST have auth + role
            if ($this->matchesPrefix($uri, $this->requireArtistRole)) {
                // Skip role-exempt routes (e.g., artist application)
                if ($this->matchesExact($uri, $this->roleExemptRoutes)) {
                    continue;
                }

                if (! $this->hasMiddleware($middleware, 'auth:sanctum')) {
                    $issues[] = [
                        'severity' => 'CRITICAL',
                        'uri' => $uri,
                        'methods' => $methods,
                        'issue' => 'Artist route MISSING auth:sanctum middleware',
                        'middleware' => $middlewareStr,
                    ];
                }
                if (! $this->hasRoleProtection($middleware, $this->artistAccessMiddlewareAliases)) {
                    $issues[] = [
                        'severity' => 'HIGH',
                        'uri' => $uri,
                        'methods' => $methods,
                        'issue' => 'Artist route MISSING role middleware',
                        'middleware' => $middlewareStr,
                    ];
                }

                continue;
            }

            // Check state-changing routes (POST/PUT/DELETE/PATCH) must have auth
            if (array_intersect(['POST', 'PUT', 'DELETE', 'PATCH'], $route->methods())) {
                if (! $this->hasMiddleware($middleware, 'auth:sanctum')
                    && ! $this->hasMiddleware($middleware, 'auth')
                    && ! $this->hasMiddleware($middleware, 'observability.collector')
                    && ! $this->hasMiddleware($middleware, 'webhook.rate_limit')) {
                    $issues[] = [
                        'severity' => 'HIGH',
                        'uri' => $uri,
                        'methods' => $methods,
                        'issue' => 'State-changing route without auth middleware',
                        'middleware' => $middlewareStr,
                    ];
                }
            }
        }

        // Display results
        if (empty($issues)) {
            $this->info('✅ No security issues found!');

            return Command::SUCCESS;
        }

        $criticals = array_filter($issues, fn ($i) => $i['severity'] === 'CRITICAL');
        $highs = array_filter($issues, fn ($i) => $i['severity'] === 'HIGH');
        $mediums = array_filter($issues, fn ($i) => $i['severity'] === 'MEDIUM');

        if (! empty($criticals)) {
            $this->error('🔴 CRITICAL Issues ('.count($criticals).')');
            $this->table(['Severity', 'Methods', 'URI', 'Issue', 'Current Middleware'], array_map(fn ($i) => [
                $i['severity'], $i['methods'], $i['uri'], $i['issue'], $i['middleware'] ?: '(none)',
            ], $criticals));
        }

        if (! empty($highs)) {
            $this->warn('🟠 HIGH Issues ('.count($highs).')');
            $this->table(['Severity', 'Methods', 'URI', 'Issue', 'Current Middleware'], array_map(fn ($i) => [
                $i['severity'], $i['methods'], $i['uri'], $i['issue'], $i['middleware'] ?: '(none)',
            ], $highs));
        }

        if (! empty($mediums)) {
            $this->line('🟡 MEDIUM Issues ('.count($mediums).')');
            $this->table(['Severity', 'Methods', 'URI', 'Issue', 'Current Middleware'], array_map(fn ($i) => [
                $i['severity'], $i['methods'], $i['uri'], $i['issue'], $i['middleware'] ?: '(none)',
            ], $mediums));
        }

        $this->newLine();
        $this->info('Total: '.count($criticals).' critical, '.count($highs).' high, '.count($mediums).' medium');

        if ($this->option('fail-on-issues') && (count($criticals) > 0 || count($highs) > 0)) {
            $this->error('Security audit FAILED — fix critical/high issues before deployment.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function matchesPrefix(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function matchesExact(string $uri, array $routes): bool
    {
        return in_array($uri, $routes, true);
    }

    private function hasMiddleware(array $middleware, string $search): bool
    {
        foreach ($middleware as $m) {
            if (str_contains($m, $search)) {
                return true;
            }
        }

        return false;
    }

    private function hasRoleProtection(array $middleware, array $customAliases = []): bool
    {
        if ($this->hasMiddleware($middleware, 'role')) {
            return true;
        }

        foreach ($customAliases as $alias) {
            if ($this->hasMiddleware($middleware, $alias)) {
                return true;
            }
        }

        return false;
    }
}
