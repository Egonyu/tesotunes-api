<?php

namespace App\Modules\Sacco\Http\Middleware;

use App\Models\Sacco\SaccoMember;
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
                'message' => 'Authentication required',
            ], 401);
        }

        // Allow super admins and admins to bypass SACCO membership requirement
        if (auth()->user()->hasAnyRole(['super_admin', 'admin'])) {
            return $next($request);
        }

        // Check if SACCO module is enabled
        if (! config('sacco.enabled', false)) {
            return response()->json([
                'message' => 'SACCO module is currently unavailable',
            ], 503);
        }

        $member = SaccoMember::where('user_id', auth()->id())->first();

        if (! $member) {
            return response()->json([
                'message' => 'SACCO membership required. Please join SACCO to access this feature.',
            ], 403);
        }

        if ($member->status !== 'active') {
            $message = match ($member->status) {
                'pending', 'pending_approval' => 'Your SACCO membership application is pending approval',
                'suspended' => 'Your SACCO membership is currently suspended',
                'inactive', 'resigned' => 'Your SACCO membership is inactive',
                'deceased' => 'This SACCO membership is no longer active',
                'rejected' => 'Your SACCO membership application was rejected',
                default => 'Your SACCO membership is not active',
            };

            return response()->json([
                'message' => $message,
            ], 403);
        }

        // Attach member to request for easy access in controllers
        $request->merge(['sacco_member' => $member]);

        return $next($request);
    }
}
