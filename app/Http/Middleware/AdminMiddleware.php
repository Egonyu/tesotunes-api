<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            return redirect()->route('admin.login');
        }

        if (!$user->isActive()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is suspended'
                ], 403);
            }
            return redirect()->route('admin.login')->with('error', 'Account is suspended');
        }

        // Allow admin and moderator access
        if (!$user->isAdmin() && !$user->isModerator()) {
            abort(403, 'Admin or moderator access required');
        }

        return $next($request);
    }
}