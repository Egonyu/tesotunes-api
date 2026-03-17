<?php

use App\Services\Monitoring\AlertingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['auth:sanctum'],
        ]
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Allow health check even during maintenance mode
        $middleware->preventRequestsDuringMaintenance(except: [
            'api/health',
            'api/health/*',
            'up',
        ]);

        // Enable API rate limiting (defined in AppServiceProvider)
        $middleware->throttleApi('api');

        // Exclude API endpoints from CSRF verification for the SPA/API contract.
        $middleware->validateCsrfTokens(except: [
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

        // Set CDN-friendly Cache-Control headers on API responses
        $middleware->appendToGroup('api', \App\Http\Middleware\CacheHeadersMiddleware::class);

        // Enforce max per_page/limit on all API list endpoints
        $middleware->appendToGroup('api', \App\Http\Middleware\EnforcePaginationMiddleware::class);

        // Track API usage analytics (async via queued job)
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackApiUsage::class);

        // For a pure API backend, unauthenticated requests should get JSON 401
        // instead of being redirected to a login page
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── Report: send alerts for server errors ────────────────────
        $exceptions->report(function (\Throwable $e) {
            $request = request();

            if ($request && ($request->is('api/*') || $request->expectsJson())) {
                if ($e instanceof AuthenticationException) {
                    Log::channel('security')->warning('api.authentication_failed', [
                        'guards' => $e->guards(),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'user_id' => auth()->id(),
                    ]);
                }

                if ($e instanceof AuthorizationException) {
                    Log::channel('security')->warning('api.authorization_denied', [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'user_id' => auth()->id(),
                        'message' => $e->getMessage(),
                    ]);
                }

                if ($e instanceof TooManyRequestsHttpException) {
                    Log::channel('security')->warning('api.rate_limited', [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'user_id' => auth()->id(),
                        'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                    ]);
                }
            }

            // Skip noise — 404s, validation, auth, and client errors
            if ($e instanceof NotFoundHttpException) {
                return false;
            }
            if ($e instanceof ValidationException) {
                return false;
            }
            if ($e instanceof AuthenticationException) {
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
                $buildPayload = function (string $message, array $extra = []) use ($e) {
                    $payload = array_merge([
                        'success' => false,
                        'message' => $message,
                    ], $extra);

                    if (! app()->isProduction()) {
                        $payload['exception'] = get_class($e);
                        $payload['trace'] = collect($e->getTrace())->take(5)->map(function ($frame) {
                            unset($frame['args']);

                            return $frame;
                        })->toArray();
                    }

                    return $payload;
                };

                $status = 500;
                $message = 'An unexpected error occurred.';

                if ($e instanceof HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                    $message = $e->getMessage() ?: $message;
                }

                if ($e instanceof ValidationException) {
                    return response()->json(
                        $buildPayload('Validation failed.', [
                            'errors' => $e->errors(),
                        ]),
                        422
                    );
                }

                if ($e instanceof AuthenticationException) {
                    return response()->json($buildPayload('Unauthenticated.'), 401);
                }

                if ($e instanceof AuthorizationException) {
                    return response()->json($buildPayload('Forbidden.'), 403);
                }

                if ($e instanceof ModelNotFoundException) {
                    return response()->json($buildPayload('The requested resource was not found.'), 404);
                }

                if ($e instanceof QueryException) {
                    return response()->json(
                        $buildPayload('A database error occurred. Please try again later.'),
                        500
                    );
                }

                if ($e instanceof TooManyRequestsHttpException || $status === 429) {
                    $retryAfter = (int) ($e instanceof HttpExceptionInterface
                        ? ($e->getHeaders()['Retry-After'] ?? 0)
                        : 0);

                    return response()->json(
                        $buildPayload('Too many attempts. Please try again later.', array_filter([
                            'retry_after' => $retryAfter ?: null,
                        ])),
                        429
                    )->header('Retry-After', $retryAfter ?: 60);
                }

                if (! $request->is('api/*') && $e instanceof MethodNotAllowedHttpException) {
                    return response()->json(
                        $buildPayload('The requested resource was not found.'),
                        404
                    );
                }

                if ($status === 404) {
                    $message = 'The requested resource was not found.';
                } elseif ($status >= 400 && $status < 500 && empty($e->getMessage())) {
                    $message = 'The request could not be processed.';
                }

                return response()->json($buildPayload($message), $status);
            }
        });

    })->create();
