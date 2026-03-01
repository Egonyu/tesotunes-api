<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait HandlesApiErrors
{
    /**
     * Execute a callback within a try-catch block and return a standardized error response on failure.
     */
    protected function handleApiAction(callable $callback, string $errorMessage = 'An error occurred.'): JsonResponse
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            \Log::error(class_basename($this) . ' error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
