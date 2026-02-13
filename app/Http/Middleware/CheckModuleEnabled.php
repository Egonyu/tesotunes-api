<?php

namespace App\Http\Middleware;

use App\Helpers\CacheHelper;
use App\Models\ModuleSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $moduleName): Response
    {
        $isEnabled = CacheHelper::remember(
            ['modules'],
            "module:enabled:{$moduleName}",
            3600,
            fn() => ModuleSetting::where('module_name', $moduleName)
                        ->where('is_enabled', true)
                        ->exists()
        );
        
        if (!$isEnabled) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'This feature is currently unavailable.'
                ], 503);
            }
            
            abort(503, 'This feature is currently unavailable.');
        }
        
        return $next($request);
    }
}
