<?php

namespace App\Http\Middleware;

use App\Modules\Contributions\Support\ContributionsModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the contributor-facing Ateso corpus routes on the runtime toggle, so an
 * admin can switch the whole feature off from the panel without a deploy.
 */
class EnsureContributionsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ContributionsModule::enabled()) {
            return response()->json([
                'success' => false,
                'message' => 'The contributions feature is currently unavailable.',
            ], 503);
        }

        return $next($request);
    }
}
