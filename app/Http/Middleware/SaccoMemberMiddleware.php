<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\SaccoMembershipService;

class SaccoMemberMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('frontend.login')->with('info', 'Please login to access SACCO features.');
        }

        $user = auth()->user();

        // Auto-create SACCO membership if user doesn't have one
        if (!$user->isSaccoMember()) {
            $service = app(SaccoMembershipService::class);
            $member = $service->autoCreateMembership($user);

            // If membership creation failed (user not eligible), redirect to join page
            if (!$member) {
                return redirect()->route('sacco.landing')->with('info', 'Complete your profile to access SACCO features.');
            }

            // Refresh user relationship
            $user->load('saccoMember');
        }

        return $next($request);
    }
}
