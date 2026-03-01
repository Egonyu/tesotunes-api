<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Standardized API response helpers.
 *
 * Every JSON response includes a `success` boolean
 * and consistent key names for data, message, and meta.
 */
trait ApiResponseTrait
{
    /**
     * Return a successful JSON response.
     */
    protected function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * Return a paginated JSON response with standard meta.
     */
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $message = null,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload);
    }

    /**
     * Return a 201 Created response.
     */
    protected function createdResponse(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Return a 204 No Content (empty body).
     */
    protected function deletedResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return an error response.
     */
    protected function errorResponse(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
