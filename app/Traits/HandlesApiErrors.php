<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait HandlesApiErrors
{
    /**
     * Execute a callback within a try-catch block and return a standardized error response on failure.
     *
     * ValidationException is re-thrown so Laravel's exception handler returns
     * a proper 422 with per-field errors — never wrap it as a generic 500.
     */
    protected function handleApiAction(callable $callback, string $errorMessage = 'An error occurred.'): JsonResponse
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            // Let Laravel handle this — returns 422 with { message, errors }
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error(class_basename($this).' error', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'location' => $e->getFile().':'.$e->getLine(),
                    'fallback_message' => $errorMessage,
                ], 500);
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'error' => 'Internal server error',
            ], 500);
        }
    }
}
