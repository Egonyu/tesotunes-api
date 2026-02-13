<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AuditLog;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthorized();
        }

        if (!$user->isActive()) {
            return $this->forbidden('Account is suspended');
        }

        if ($user->isSuspended()) {
            return $this->forbidden('Account is suspended');
        }

        if (empty($permissions)) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if (!$user->hasPermission($permission)) {
                // Log unauthorized access attempt
                AuditLog::logActivity($user->id, 'unauthorized_access_attempt', [
                    'permission' => $permission,
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ]);

                return $this->forbidden("Missing required permission: {$permission}");
            }
        }

        return $next($request);
    }

    protected function unauthorized(): Response
    {
        if (request()->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        return redirect()->route('login')->with('error', 'Authentication required');
    }

    protected function forbidden(string $message): Response
    {
        if (request()->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message
            ], 403);
        }

        abort(403, $message);
    }
}