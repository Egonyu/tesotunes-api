<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to wrap admin API requests with standardized error handling.
 *
 * Addresses HIGH-8: 14 of 16 admin controllers had ZERO try-catch,
 * meaning any DB error would return a raw 500 to the client.
 * This middleware catches common exception types, logs them with
 * admin-specific context, and returns consistent JSON responses.
 */
class HandleAdminExceptions
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ModelNotFoundException $e) {
            $model = class_basename($e->getModel());

            Log::warning('Admin: resource not found', [
                'model' => $model,
                'ids' => $e->getIds(),
                'admin_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "{$model} not found.",
            ], 404);

        } catch (QueryException $e) {
            Log::error('Admin: database error', [
                'message' => $e->getMessage(),
                'sql' => app()->isProduction() ? '[hidden]' : $e->getSql(),
                'admin_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            // Detect specific SQL errors for better messages
            $errorCode = $e->errorInfo[1] ?? null;
            $message = match ($errorCode) {
                1062 => 'A record with this value already exists.',
                1452 => 'Referenced record does not exist.',
                1451 => 'Cannot delete this record because it is referenced by other records.',
                1054 => 'A database column error occurred.',
                default => 'A database error occurred. Please try again later.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 500);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Admin: invalid argument', [
                'message' => $e->getMessage(),
                'admin_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Admin: unhandled exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'admin_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            $payload = [
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again later.',
            ];

            if (! app()->isProduction()) {
                $payload['exception'] = get_class($e);
                $payload['debug_message'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }
}
