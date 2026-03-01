<?php

use App\Services\Monitoring\AlertingService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Enable API rate limiting (defined in AppServiceProvider)
        $middleware->throttleApi('api');

        // Exclude auth endpoints from CSRF verification (for API/NextAuth)
        $middleware->validateCsrfTokens(except: [
            '/auth/login',
            '/auth/register',
            '/api/*',
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.exceptions' => \App\Http\Middleware\HandleAdminExceptions::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'feature' => \App\Http\Middleware\FeatureMiddleware::class,
            'api.rate_limit' => \App\Http\Middleware\ApiRateLimitMiddleware::class,
            'secure.upload' => \App\Http\Middleware\SecureFileUploadMiddleware::class,
            'sacco.member' => \App\Http\Middleware\SaccoMemberMiddleware::class,
            'sacco.member.api' => \App\Http\Middleware\CheckSaccoMembershipApi::class,
            'module.enabled' => \App\Http\Middleware\CheckModuleEnabled::class,
            'check.environment' => \App\Http\Middleware\CheckEnvironment::class,
            'webhook.rate_limit' => \App\Http\Middleware\WebhookRateLimiter::class,
            'loyalty.tier' => \App\Http\Middleware\CheckLoyaltyTierAccess::class,
            'deprecated' => \App\Http\Middleware\DeprecationMiddleware::class,
        ]);

        // Add security headers to all requests
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        // Log API requests (method, URI, status, duration, user)
        $middleware->appendToGroup('api', \App\Http\Middleware\ApiLoggingMiddleware::class);

        // For a pure API backend, unauthenticated requests should get JSON 401
        // instead of being redirected to a login page
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── Report: send alerts for server errors ────────────────────
        $exceptions->report(function (\Throwable $e) {
            // Skip noise — 404s, validation, auth, and client errors
            if ($e instanceof NotFoundHttpException) {
                return false;
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return false;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return false;
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return false;
            }

            try {
                $alerting = app(AlertingService::class);

                // Deduplicate by exception class + file + line
                $alertKey = 'exception_'.md5(get_class($e).$e->getFile().$e->getLine());

                $context = [
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 500),
                    'file' => $e->getFile().':'.$e->getLine(),
                    'url' => request()?->fullUrl(),
                    'method' => request()?->method(),
                    'user_id' => auth()->id(),
                    'ip' => request()?->ip(),
                    'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
                ];

                // Payment & queue failures get higher severity
                $severity = AlertingService::HIGH;
                $lowerClass = strtolower(get_class($e));
                if (str_contains($lowerClass, 'payment')
                    || str_contains($lowerClass, 'money')
                    || str_contains($e->getFile(), 'Payment')
                    || str_contains($e->getFile(), 'Queue')) {
                    $severity = AlertingService::CRITICAL;
                }

                $alerting->alert(
                    $severity,
                    $alertKey,
                    get_class($e).': '.mb_substr($e->getMessage(), 0, 200),
                    $context
                );
            } catch (\Throwable) {
                // Alerting failures must never suppress the original error
            }

            // Return false so Laravel's default logger still fires
            return false;
        });

        // ── Render: consistent JSON error responses for API routes ───
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = 500;
                $message = 'An unexpected error occurred.';

                if ($e instanceof HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                    $message = $e->getMessage() ?: $message;
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $status = 401;
                    $message = 'Unauthenticated.';
                }

                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'errors' => $e->errors(),
                    ], 422);
                }

                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $status = 404;
                    $message = 'The requested resource was not found.';
                }

                if ($e instanceof \Illuminate\Database\QueryException) {
                    $status = 500;
                    $message = 'A database error occurred. Please try again later.';
                }

                // In production, never leak stack traces
                $payload = [
                    'success' => false,
                    'message' => $message,
                ];
                if (! app()->isProduction()) {
                    $payload['exception'] = get_class($e);
                    $payload['trace'] = collect($e->getTrace())->take(5)->toArray();
                }

                return response()->json($payload, $status);
            }
        });

    })->create();
