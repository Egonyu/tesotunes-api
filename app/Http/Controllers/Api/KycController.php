<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Kyc\ReviewKycRequest;
use App\Http\Requests\Api\Kyc\UploadKycDocumentRequest;
use App\Http\Resources\KycStatusResource;
use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * GET /api/kyc/status — current user's KYC state, docs, and requirements.
     */
    public function status(Request $request): KycStatusResource
    {
        return new KycStatusResource($request->user()->load('kycDocuments'));
    }

    /**
     * GET /api/kyc/requirements/{action} — what the current user is missing
     * to perform a given sensitive action. The frontend uses this to render
     * the contextual "complete verification" stepper.
     */
    public function requirements(Request $request, string $action): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'action' => $action,
                'eligible' => $this->kyc->eligibleFor($user, $action),
                'missing_steps' => $this->kyc->missingStepsFor($user, $action),
                'current_status' => $this->kyc->currentStatus($user)->value,
            ],
        ]);
    }

    /**
     * POST /api/kyc/documents — submit a single KYC document.
     */
    public function uploadDocument(UploadKycDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->kyc->submitDocument(
                user: $request->user(),
                type: $request->documentType(),
                file: $request->file('file'),
                documentNumber: $request->input('document_number'),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded. Awaiting review.',
            'data' => [
                'document_id' => $document->id,
                'document_type' => $document->document_type->value,
                'status' => $document->status->value,
                'kyc_status' => $this->kyc->currentStatus($request->user()->refresh())->value,
            ],
        ], 201);
    }

    /**
     * POST /api/admin/kyc/users/{user}/review — admin approves or rejects.
     */
    public function review(ReviewKycRequest $request, User $user): JsonResponse
    {
        $admin = $request->user();
        $decision = $request->string('decision')->toString();

        if ($decision === 'approve') {
            $this->kyc->markVerified($user, $admin, $request->input('notes'));
        } else {
            $this->kyc->markRejected($user, $admin, $request->string('reason')->toString());
        }

        return response()->json([
            'success' => true,
            'message' => $decision === 'approve' ? 'KYC approved.' : 'KYC rejected.',
            'data' => new KycStatusResource($user->refresh()->load('kycDocuments')),
        ]);
    }

    /**
     * GET /api/admin/kyc/pending — list users awaiting review.
     */
    public function pending(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $users = User::query()
            ->where('kyc_status', \App\Enums\KycStatus::PendingReview)
            ->with('kycDocuments')
            ->orderBy('kyc_submitted_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->getCollection()->map(
                fn (User $u) => (new KycStatusResource($u))->toArray($request)
                    + ['user_id' => $u->id, 'email' => $u->email, 'full_name' => $u->full_name]
            ),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
