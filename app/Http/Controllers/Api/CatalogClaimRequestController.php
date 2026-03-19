<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\AuditLog;
use App\Models\CatalogClaimRequest;
use App\Notifications\AdminCatalogClaimPendingNotification;
use App\Notifications\CatalogClaimStatusNotification;
use App\Services\NotificationRoutingService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogClaimRequestController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly NotificationRoutingService $notificationRoutingService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $claims = CatalogClaimRequest::query()
                ->with(['artist', 'claimant', 'reviewer'])
                ->where('claimant_user_id', $request->user()->id)
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 20), 100));

            return response()->json([
                'data' => $claims,
            ]);
        }, 'Failed to fetch your claim requests.');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'artist_id' => 'required|integer|exists:artists,id',
                'song_ids' => 'nullable|array',
                'song_ids.*' => 'integer|exists:songs,id',
                'phone_number' => 'nullable|string|max:50',
                'message' => 'required|string|max:2000',
                'evidence' => 'nullable|array',
                'evidence.*' => 'string|max:1000',
            ]);

            $artist = Artist::query()->findOrFail($validated['artist_id']);
            if (! $artist->is_placeholder) {
                return response()->json([
                    'message' => 'Only placeholder artists can be claimed through this workflow.',
                ], 422);
            }

            $requestedSongIds = collect($validated['song_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($requestedSongIds !== []) {
                $validSongIds = $artist->songs()->whereIn('id', $requestedSongIds)->pluck('id')->all();
                if (count($validSongIds) !== count($requestedSongIds)) {
                    return response()->json([
                        'message' => 'One or more requested songs do not belong to the selected artist.',
                    ], 422);
                }
            }

            $duplicate = CatalogClaimRequest::query()
                ->where('claimant_user_id', $request->user()->id)
                ->where('artist_id', $artist->id)
                ->whereIn('status', ['pending', 'under_review'])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'You already have an active claim request for this artist.',
                ], 422);
            }

            $claim = CatalogClaimRequest::create([
                'claimant_user_id' => $request->user()->id,
                'artist_id' => $artist->id,
                'requested_song_ids' => $requestedSongIds,
                'phone_number' => $validated['phone_number'] ?? null,
                'message' => $validated['message'],
                'evidence' => $validated['evidence'] ?? null,
                'status' => 'pending',
            ]);

            AuditLog::logActivity($request->user()->id, 'catalog_claim_submitted', [
                'claim_id' => $claim->id,
                'artist_id' => $artist->id,
            ]);

            $claim->load(['artist', 'claimant']);
            $request->user()->notify(new CatalogClaimStatusNotification($claim, CatalogClaimStatusNotification::SUBMITTED));

            foreach ($this->notificationRoutingService->claimReviewRecipients() as $reviewer) {
                $reviewer->notify(new AdminCatalogClaimPendingNotification($claim));
            }

            return response()->json([
                'message' => 'Claim request submitted successfully.',
                'data' => $claim,
            ], 201);
        }, 'Failed to submit claim request.');
    }
}
