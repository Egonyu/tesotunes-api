<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Observability\SecurityDomain;
use App\Http\Controllers\Controller;
use App\Http\Resources\Observability\SecurityEventResource;
use App\Services\Observability\SecurityConsoleService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read API for the rebuilt security console.
 *
 * Unlike the legacy ObservabilityController, nothing here runs a sync on
 * read — every endpoint queries the push-recorded event stream directly.
 */
class SecurityConsoleController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly SecurityConsoleService $console,
    ) {}

    public function posture(Request $request): Response
    {
        return $this->handleApiAction(function () use ($request): JsonResponse {
            return response()->json([
                'success' => true,
                'data' => $this->console->posture($this->filters($request)),
            ]);
        }, 'Failed to load security posture.');
    }

    public function feed(Request $request): Response
    {
        return $this->handleApiAction(function () use ($request): JsonResponse {
            $paginator = $this->console->feed($this->filters($request), $this->perPage($request));

            return response()->json([
                'success' => true,
                'data' => SecurityEventResource::collection($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }, 'Failed to load the security event feed.');
    }

    public function incidents(Request $request): Response
    {
        return $this->handleApiAction(function (): JsonResponse {
            return response()->json([
                'success' => true,
                'data' => $this->console->incidents(),
            ]);
        }, 'Failed to load security incidents.');
    }

    public function domain(Request $request, string $domain): Response
    {
        return $this->handleApiAction(function () use ($request, $domain): JsonResponse {
            $resolved = SecurityDomain::tryFrom($domain);

            if ($resolved === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unknown security domain.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->console->domainSummary($resolved->value, $this->filters($request)),
            ]);
        }, 'Failed to load the domain summary.');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'domain' => array_values(array_filter((array) $request->query('domain', []))),
            'severity' => array_values(array_filter((array) $request->query('severity', []))),
            'outcome' => array_values(array_filter((array) $request->query('outcome', []))),
            'min_risk' => $request->query('min_risk'),
            'search' => $request->query('search'),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }
}
