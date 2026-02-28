<?php

namespace App\Http\Middleware;

use App\Modules\Sacco\Models\SaccoMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSaccoMembershipApi
{
    /**
     * Validate SACCO membership for API routes.
     *
     * Returns JSON responses instead of redirects (unlike CheckSaccoMembership).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        // Check if SACCO module is enabled
        if (! config('sacco.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'SACCO module is currently unavailable',
            ], 503);
        }

        $member = SaccoMember::where('user_id', auth()->id())->first();

        if (! $member) {
            return response()->json([
                'success' => false,
                'message' => 'SACCO membership required. Please join SACCO to access this feature.',
            ], 403);
        }

        if ($member->status !== 'active') {
            $message = match ($member->status) {
                'pending' => 'Your SACCO membership application is pending approval',
                'suspended' => 'Your SACCO membership is currently suspended',
                'inactive' => 'Your SACCO membership is inactive',
                'rejected' => 'Your SACCO membership application was rejected',
                default => 'Your SACCO membership is not active',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        // Attach member to request for easy access in controllers
        $request->merge(['sacco_member' => $member]);

        return $next($request);
    }
}
