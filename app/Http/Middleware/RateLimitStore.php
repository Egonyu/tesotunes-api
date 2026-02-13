<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store Module Rate Limiting Middleware
 * 
 * Implements tiered rate limiting for Store API endpoints
 */
class RateLimitStore
{
    public function __construct(
        protected RateLimiter $limiter
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $tier = 'default'): Response
    {
        $key = $this->resolveRequestSignature($request);
        $limit = $this->getLimit($tier, $request);

        if ($this->limiter->tooManyAttempts($key, $limit['max'])) {
            return $this->buildResponse($key, $limit['max']);
        }

        $this->limiter->hit($key, $limit['decay']);

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $limit['max'],
            $this->limiter->retriesLeft($key, $limit['max'])
        );
    }

    /**
     * Resolve request signature
     */
    protected function resolveRequestSignature(Request $request): string
    {
        if ($user = $request->user()) {
            return 'store:' . sha1($user->id);
        }

        return 'store:' . sha1($request->ip());
    }

    /**
     * Get rate limit configuration for tier
     */
    protected function getLimit(string $tier, Request $request): array
    {
        $user = $request->user();
        
        return match($tier) {
            // Public browsing - generous limits
            'public' => [
                'max' => 100,
                'decay' => 60, // per minute
            ],
            
            // Authenticated general actions
            'authenticated' => [
                'max' => $user?->hasRole('premium') ? 120 : 60,
                'decay' => 60,
            ],
            
            // Cart operations - moderate limits
            'cart' => [
                'max' => 30,
                'decay' => 60,
            ],
            
            // Order creation - strict limits
            'checkout' => [
                'max' => 10,
                'decay' => 60,
            ],
            
            // Payment operations - very strict
            'payment' => [
                'max' => 5,
                'decay' => 60,
            ],
            
            // Store management - moderate
            'seller' => [
                'max' => 60,
                'decay' => 60,
            ],
            
            // Product uploads - strict
            'upload' => [
                'max' => 10,
                'decay' => 60,
            ],
            
            // Search/filter operations
            'search' => [
                'max' => 40,
                'decay' => 60,
            ],
            
            default => [
                'max' => 60,
                'decay' => 60,
            ],
        };
    }

    /**
     * Create a 'too many attempts' response
     */
    protected function buildResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429)->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->timestamp,
            'Retry-After' => $retryAfter,
        ]);
    }

    /**
     * Add rate limit headers to response
     */
    protected function addHeaders(Response $response, int $maxAttempts, int $remainingAttempts): Response
    {
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ]);

        return $response;
    }
}
