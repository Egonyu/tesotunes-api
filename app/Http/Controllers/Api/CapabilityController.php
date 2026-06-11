<?php

namespace App\Http\Controllers\Api;

use App\Enums\Capability;
use App\Enums\CapabilityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Capabilities\ApplyOrganizerRequest;
use App\Http\Requests\Api\Capabilities\ReviewCapabilityRequest;
use App\Models\Accounts\UserCapability;
use App\Services\Accounts\CapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilityController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    /**
     * GET /api/capabilities — the current user's capability posture.
     * Powers the account-mode switcher on the frontend.
     */
    public function index(Request $request): JsonResponse
    {
        $grants = $request->user()->capabilities()->get()->keyBy(fn (UserCapability $g) => $g->capability->value);

        $data = collect(Capability::cases())->map(function (Capability $capability) use ($grants) {
            $grant = $grants->get($capability->value);

            return [
                'capability' => $capability->value,
                'label' => $capability->label(),
                'status' => $grant?->status->value ?? 'none',
                'applied_at' => $grant?->applied_at?->toIso8601String(),
                'granted_at' => $grant?->granted_at?->toIso8601String(),
                'status_reason' => $grant?->status_reason,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/capabilities/organizer/apply — self-service organizer
     * onboarding (previously admin-only via a settings-JSON flag).
     */
    public function applyOrganizer(ApplyOrganizerRequest $request): JsonResponse
    {
        try {
            $grant = $this->capabilities->apply(
                $request->user(),
                Capability::Organizer,
                $request->validated(),
            );
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => $grant->status === CapabilityStatus::Granted
                ? 'You already have organizer access.'
                : 'Application submitted. We will review it within 24-48 hours.',
            'data' => [
                'capability' => $grant->capability->value,
                'status' => $grant->status->value,
                'applied_at' => $grant->applied_at?->toIso8601String(),
            ],
        ], $grant->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET /api/admin/capabilities/pending — applications awaiting review.
     */
    public function pending(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $applications = UserCapability::query()
            ->pending()
            ->when($request->filled('capability'), function ($query) use ($request) {
                $capability = Capability::tryFrom((string) $request->query('capability'));
                $capability && $query->ofCapability($capability);
            })
            ->with('user:id,email,display_name')
            ->orderBy('applied_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $applications->getCollection()->map(fn (UserCapability $grant) => [
                'id' => $grant->id,
                'capability' => $grant->capability->value,
                'status' => $grant->status->value,
                'applied_at' => $grant->applied_at?->toIso8601String(),
                'application' => $grant->application,
                'user' => [
                    'id' => $grant->user->id,
                    'email' => $grant->user->email,
                    'name' => $grant->user->display_name,
                ],
            ]),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/capabilities/{capability}/review — grant or reject.
     */
    public function review(ReviewCapabilityRequest $request, UserCapability $capability): JsonResponse
    {
        try {
            if ($request->string('decision')->toString() === 'grant') {
                $grant = $this->capabilities->grant(
                    $capability->user,
                    $capability->capability,
                    grantedBy: $request->user(),
                    requireKyc: true,
                );
            } else {
                $grant = $this->capabilities->reject(
                    $capability,
                    $request->string('reason')->toString(),
                    $request->user(),
                );
            }
        } catch (\DomainException|\LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $grant->status === CapabilityStatus::Granted ? 'Capability granted.' : 'Application rejected.',
            'data' => [
                'id' => $grant->id,
                'capability' => $grant->capability->value,
                'status' => $grant->status->value,
            ],
        ]);
    }
}
