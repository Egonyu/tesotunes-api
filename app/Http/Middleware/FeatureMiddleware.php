<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;

class FeatureMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Check if the feature is enabled in settings
        $isEnabled = $this->isFeatureEnabled($feature);

        if (!$isEnabled) {
            // If the feature is disabled, return 404 or redirect based on the feature
            return $this->handleDisabledFeature($request, $feature);
        }

        return $next($request);
    }

    /**
     * Check if a feature is enabled
     */
    private function isFeatureEnabled(string $feature): bool
    {
        switch ($feature) {
            case 'mobile_verification':
                return Setting::get('phone_verification_enabled', true);

            case 'awards':
                return Setting::get('awards_system_enabled', true);

            case 'events':
                return Setting::get('events_module_enabled', true);

            case 'tickets':
                return Setting::get('ticket_sales_enabled', true);

            case 'artist_registration':
                return Setting::get('artist_registration_enabled', true);

            case 'music_streaming':
                return Setting::get('music_streaming_enabled', true);

            case 'music_downloads':
                return Setting::get('music_downloads_enabled', true);

            case 'social_features':
                return Setting::get('social_features_enabled', true);

            case 'promotions':
                return Setting::get('community_promotions_enabled', true);

            case 'credits':
                return Setting::get('credit_system_enabled', true);

            case 'subscriptions':
                return Setting::get('subscription_system_enabled', true);

            case 'playlists':
                return Setting::get('playlist_creation_enabled', true);

            default:
                return true; // If feature is not recognized, allow it
        }
    }

    /**
     * Handle disabled feature access
     */
    private function handleDisabledFeature(Request $request, string $feature): Response
    {
        // For AJAX requests, return JSON error
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Feature not available',
                'message' => "The {$feature} feature is currently disabled."
            ], 404);
        }

        // For regular requests, handle based on feature type
        switch ($feature) {
            case 'mobile_verification':
                // Redirect to login or dashboard if phone verification is disabled
                if (auth()->check()) {
                    return redirect()->route('frontend.dashboard');
                }
                return redirect()->route('login'); // Use global auth route name

            case 'awards':
                return redirect()->route('frontend.home')
                    ->with('error', 'Awards system is currently disabled.');

            case 'events':
            case 'tickets':
                return redirect()->route('frontend.home')
                    ->with('error', 'Event system is currently disabled.');

            case 'artist_registration':
                return redirect()->route('register') // Use global auth route name
                    ->with('error', 'Artist registration is currently disabled.');

            default:
                // Generic 404 for other disabled features
                abort(404);
        }
    }
}