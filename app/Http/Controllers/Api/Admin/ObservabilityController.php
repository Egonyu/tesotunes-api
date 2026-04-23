<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObservabilityIncident;
use App\Services\Observability\ObservabilityService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObservabilityController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        protected ObservabilityService $observability,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->overview($filters));
    }

    /**
     * Lean posture endpoint — only the four KPIs the v2 Overview section renders.
     * Cheaper than overview(); see rebuild plan §4 item 2.
     */
    public function posture(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->posture($filters));
    }

    public function events(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $this->observability->syncPhaseOneData();
            $paginator = $this->observability->events($this->filters($request), $this->perPage($request));

            return response()->json([
                'success' => true,
                'filters' => $this->filters($request),
                'summary' => [
                    'total' => $paginator->total(),
                ],
                'data' => $this->observability->serializeEventCollection(collect($paginator->items())),
                'meta' => $this->paginationMeta($paginator),
            ]);
        }, 'Failed to load observability events.');
    }

    public function showEvent(Request $request, string $event): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->eventDetail($event));
    }

    public function entryPoints(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->entryPoints($filters));
    }

    public function attackers(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->attackers($filters));
    }

    public function showAttacker(Request $request, string $attacker): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->attackerDetail($attacker));
    }

    public function bots(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->bots($filters));
    }

    public function authSessions(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->authSessions($filters));
    }

    public function showAuthSession(Request $request, string $session): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->authSessionDetail($session));
    }

    public function showPaymentReference(Request $request, string $reference): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->paymentReferenceDetail($reference));
    }

    public function paymentsRisk(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->paymentsRisk($filters));
    }

    public function integrations(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->integrations($filters));
    }

    public function systemHost(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->systemHost($filters));
    }

    public function database(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->database($filters));
    }

    public function auditTrail(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->auditTrail($filters));
    }

    public function changes(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->changes($filters));
    }

    public function stakeholderRisk(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->stakeholderRisk($filters));
    }

    public function showStakeholder(Request $request, string $actorType, string $actorId): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->stakeholderDetail($actorType, $actorId));
    }

    public function incidents(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->incidents());
    }

    public function incidentSuggestions(Request $request): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->incidentSuggestions($filters));
    }

    public function showIncident(Request $request, ObservabilityIncident $incident): JsonResponse
    {
        return $this->wrap($request, fn (array $filters) => $this->observability->incidentDetail($incident));
    }

    public function assignIncident(Request $request, ObservabilityIncident $incident): JsonResponse
    {
        return $this->handleApiAction(function () use ($incident) {
            return response()->json([
                'success' => true,
                'data' => $this->observability->serializeIncident($this->observability->assignIncidentToCurrentUser($incident)),
            ]);
        }, 'Failed to assign incident.');
    }

    public function releaseIncident(Request $request, ObservabilityIncident $incident): JsonResponse
    {
        return $this->handleApiAction(function () use ($incident) {
            return response()->json([
                'success' => true,
                'data' => $this->observability->serializeIncident($this->observability->releaseIncidentOwnership($incident)),
            ]);
        }, 'Failed to release incident ownership.');
    }

    public function storeIncident(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $payload = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
                'severity' => ['nullable', 'string', 'max:50'],
                'owner_id' => ['nullable', 'integer'],
                'summary' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
                'started_at' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
                'event_ids' => ['nullable', 'array'],
                'event_ids.*' => ['integer'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->observability->serializeIncident($this->observability->createIncident($payload)),
            ], 201);
        }, 'Failed to create incident.');
    }

    public function updateIncident(Request $request, ObservabilityIncident $incident): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $incident) {
            $payload = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'status' => ['sometimes', 'string', 'max:50'],
                'severity' => ['sometimes', 'string', 'max:50'],
                'owner_id' => ['nullable', 'integer'],
                'summary' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
                'append_note' => ['nullable', 'string'],
                'started_at' => ['nullable', 'date'],
                'resolved_at' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
                'event_ids' => ['nullable', 'array'],
                'event_ids.*' => ['integer'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->observability->serializeIncident($this->observability->updateIncident($incident, $payload)),
            ]);
        }, 'Failed to update incident.');
    }

    protected function wrap(Request $request, callable $callback): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $callback) {
            $this->observability->syncPhaseOneData();
            $filters = $this->filters($request);

            return response()->json([
                'success' => true,
                'filters' => $filters,
                'data' => $callback($filters),
            ]);
        }, 'Failed to load observability data.');
    }

    protected function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'severity' => array_values(array_filter((array) $request->query('severity', []))),
            'domain' => array_values(array_filter((array) $request->query('domain', []))),
            'category' => array_values(array_filter((array) $request->query('category', []))),
            'outcome' => array_values(array_filter((array) $request->query('outcome', []))),
            'route' => $request->query('route'),
            'actor_type' => array_values(array_filter((array) $request->query('actor_type', []))),
            'user_id' => $request->query('user_id'),
            'admin_id' => $request->query('admin_id'),
            'ip' => $request->query('ip'),
            'asn' => $request->query('asn'),
            'country' => $request->query('country'),
            'payment_reference' => $request->query('payment_reference'),
            'host' => $request->query('host'),
            'container' => $request->query('container'),
            'incident_id' => $request->query('incident_id'),
            'search' => $request->query('search'),
        ];
    }

    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
