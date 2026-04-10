<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ArtistEventAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->isActive()) {
            abort(403, 'Account is suspended.');
        }

        if ($user->hasAnyRole(['artist', 'admin', 'super_admin']) || $user->isEventOrganizer()) {
            return $next($request);
        }

        abort(403, 'Artist or organizer access is required.');
    }
}
