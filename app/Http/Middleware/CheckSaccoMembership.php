<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\Sacco\Models\SaccoMember;

class CheckSaccoMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return redirect()->route('frontend.login')
                ->with('error', 'Please login to access SACCO features');
        }

        // Check if SACCO module is enabled
        if (!config('sacco.enabled', false)) {
            return redirect()->route('home')
                ->with('error', 'SACCO module is currently unavailable');
        }

        $member = SaccoMember::where('user_id', auth()->id())->first();

        // Check if user is a member
        if (!$member) {
            return redirect()->route('frontend.sacco.register')
                ->with('info', 'You need to join SACCO to access this feature');
        }

        // Check if membership is active
        if ($member->status !== 'active') {
            $message = match ($member->status) {
                'pending' => 'Your SACCO membership application is pending approval',
                'suspended' => 'Your SACCO membership is currently suspended',
                'inactive' => 'Your SACCO membership is inactive',
                'rejected' => 'Your SACCO membership application was rejected',
                default => 'Your SACCO membership is not active',
            };

            return redirect()->route('sacco.landing')
                ->with('warning', $message);
        }

        // Attach member to request for easy access
        $request->merge(['sacco_member' => $member]);

        return $next($request);
    }
}
