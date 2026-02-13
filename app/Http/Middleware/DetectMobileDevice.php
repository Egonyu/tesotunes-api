<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectMobileDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = $request->header('User-Agent');
        
        // Detect mobile devices
        $isMobile = $this->isMobileDevice($userAgent);
        
        // Store in request for later use
        $request->attributes->set('is_mobile', $isMobile);
        
        // Share with views
        view()->share('isMobile', $isMobile);
        
        return $next($request);
    }

    /**
     * Detect if the user agent is a mobile device
     */
    private function isMobileDevice(?string $userAgent): bool
    {
        if (!$userAgent) {
            return false;
        }

        // Mobile detection patterns
        $mobilePatterns = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod',
            'BlackBerry', 'IEMobile', 'Opera Mini', 'webOS',
            'Windows Phone', 'Kindle', 'Silk', 'PlayBook'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        // Additional check for tablet devices
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $userAgent)) {
            // You can choose to treat tablets as mobile or desktop
            // For Spotify-like experience, we treat them as mobile
            return true;
        }

        return false;
    }
}
