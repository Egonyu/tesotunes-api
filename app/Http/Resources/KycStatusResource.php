<?php

namespace App\Http\Resources;

use App\Enums\KycDocumentType;
use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class KycStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        /** @var KycService $kyc */
        $kyc = app(KycService::class);

        $status = $kyc->currentStatus($user);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'submitted_at' => $user->kyc_submitted_at?->toIso8601String(),
            'verified_at' => $user->kyc_verified_at?->toIso8601String(),
            'expires_at' => $user->kyc_expires_at?->toIso8601String(),
            'rejection_reason' => $user->kyc_rejection_reason,
            'can_submit_documents' => $status->canSubmitDocuments(),
            'eligible_for_sensitive_actions' => $status->isEligibleForSensitiveActions(),
            'documents' => $this->documentsByType($user),
            'requirements' => [
                'required_document_types' => array_map(
                    fn (KycDocumentType $t) => [
                        'type' => $t->value,
                        'label' => $t->label(),
                    ],
                    KycDocumentType::required(),
                ),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentsByType(User $user): array
    {
        return $user->kycDocuments()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'document_type' => $doc->document_type instanceof KycDocumentType
                    ? $doc->document_type->value
                    : $doc->document_type,
                'status' => $doc->status?->value ?? $doc->getAttribute('status'),
                'rejection_reason' => $doc->rejection_reason,
                'submitted_at' => $doc->created_at?->toIso8601String(),
                'verified_at' => $doc->verified_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
