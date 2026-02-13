<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // X-Frame-Options: Prevent clickjacking attacks
        $response->headers->set('X-Frame-Options', 'DENY');

        // X-Content-Type-Options: Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-XSS-Protection: Enable XSS filtering
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer-Policy: Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy: Control browser features
        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=(self)',
            'geolocation=()',
            'payment=(self)',
            'usb=()',
        ]));

        // Content Security Policy
        $csp = $this->getContentSecurityPolicy($request);
        $response->headers->set('Content-Security-Policy', $csp);

        // Strict-Transport-Security: Force HTTPS (only in production)
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    /**
     * Get Content Security Policy directive
     */
    private function getContentSecurityPolicy(Request $request): string
    {
        $domain = $request->getHost();

        // In development, allow Vite dev server (IPv4 only - IPv6 localhost not supported in CSP)
        $isDevelopment = app()->environment('local');
        $viteDevServer = $isDevelopment ? ' http://localhost:5173 http://127.0.0.1:5173 http://localhost:5174 http://127.0.0.1:5174 ws://localhost:5173 ws://127.0.0.1:5173 ws://localhost:5174 ws://127.0.0.1:5174' : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdn.tailwindcss.com{$viteDevServer}",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com{$viteDevServer}",
            "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com data:",
            "img-src 'self' data: https: blob:",
            "media-src 'self' blob: data:",
            "connect-src 'self' blob: data: https://api.{$domain}{$viteDevServer}",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'"
        ];

        // Only add upgrade-insecure-requests in production
        if (app()->isProduction()) {
            $directives[] = "upgrade-insecure-requests";
        }

        return implode('; ', $directives);
    }
}