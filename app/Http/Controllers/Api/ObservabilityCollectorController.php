<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Observability\ObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObservabilityCollectorController extends Controller
{
    public function __construct(
        protected ObservabilityService $observability,
    ) {}

    public function ingest(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_key' => ['nullable', 'string', 'max:255'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.domain' => ['required', 'string', 'max:50'],
            'events.*.category' => ['required', 'string', 'max:50'],
            'events.*.outcome' => ['required', 'string', 'max:50'],
            'events.*.severity' => ['required', 'string', 'max:50'],
            'events.*.title' => ['required', 'string', 'max:255'],
            'events.*.summary' => ['required', 'string'],
            'events.*.source' => ['nullable', 'array'],
            'events.*.actor' => ['nullable', 'array'],
            'events.*.target' => ['nullable', 'array'],
            'events.*.attack' => ['nullable', 'array'],
            'events.*.infra' => ['nullable', 'array'],
            'events.*.correlation' => ['nullable', 'array'],
            'events.*.details' => ['nullable', 'array'],
            'events.*.raw_ref' => ['nullable', 'array'],
        ]);

        $ingested = $this->observability->ingestCollectorBatch($payload['events']);

        return response()->json([
            'success' => true,
            'data' => [
                'ingested' => $ingested,
            ],
        ], 202);
    }
}
