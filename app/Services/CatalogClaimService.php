<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CatalogClaimRequest;
use App\Models\User;
use App\Notifications\CatalogClaimStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogClaimService
{
    public function approve(CatalogClaimRequest $claim, User $reviewer): CatalogClaimRequest
    {
        if ($claim->status === 'approved') {
            return $claim->fresh(['artist', 'claimant', 'reviewer']);
        }

        if (! in_array($claim->status, ['pending', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'claim' => ['Only pending or in-review claim requests can be approved.'],
            ]);
        }

        $claim->loadMissing(['artist', 'claimant']);
        $artist = $claim->artist;
        $claimant = $claim->claimant;

        if (! $artist || ! $claimant) {
            throw ValidationException::withMessages([
                'claim' => ['The claim request is missing its artist or claimant context.'],
            ]);
        }

        if (! $artist->is_placeholder) {
            throw ValidationException::withMessages([
                'artist' => ['Only placeholder artists can be claimed through this workflow.'],
            ]);
        }

        $existingArtist = $claimant->artist()->first();
        if ($existingArtist && $existingArtist->id !== $artist->id) {
            throw ValidationException::withMessages([
                'claimant_user_id' => ['This user already owns a different artist profile and needs manual review.'],
            ]);
        }

        DB::transaction(function () use ($claim, $reviewer, $artist, $claimant) {
            $artist->update([
                'user_id' => $claimant->id,
                'is_placeholder' => false,
                'claim_status' => 'claimed',
                'claimed_user_id' => $claimant->id,
                'catalog_manager_user_id' => $artist->catalog_manager_user_id,
                'can_upload' => true,
                'status' => 'active',
            ]);

            $artist->songs()
                ->where('is_claimable', true)
                ->update([
                    'user_id' => $claimant->id,
                    'is_claimable' => false,
                ]);

            $claim->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            CatalogClaimRequest::query()
                ->where('artist_id', $artist->id)
                ->where('id', '!=', $claim->id)
                ->whereIn('status', ['pending', 'under_review'])
                ->update([
                    'status' => 'rejected',
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'rejection_reason' => 'Another claim request for this artist was approved.',
                ]);

            $claimant->forceFill([
                'is_artist' => true,
            ])->save();

            if ($claim->phone_number && ! $claimant->phone) {
                $claimant->forceFill([
                    'phone' => $claim->phone_number,
                ])->save();
            }

            if (! $claimant->hasRole('artist')) {
                $claimant->assignRole('artist', $reviewer->id);
            }

            AuditLog::logActivity($reviewer->id, 'catalog_claim_approved', [
                'claim_id' => $claim->id,
                'artist_id' => $artist->id,
                'claimant_user_id' => $claimant->id,
            ]);
        });

        $approvedClaim = $claim->fresh(['artist', 'claimant', 'reviewer']);
        $claimant->notify(new CatalogClaimStatusNotification($approvedClaim, CatalogClaimStatusNotification::APPROVED));

        return $approvedClaim;
    }

    public function reject(CatalogClaimRequest $claim, User $reviewer, string $reason): CatalogClaimRequest
    {
        if ($claim->status === 'rejected') {
            return $claim->fresh(['artist', 'claimant', 'reviewer']);
        }

        $claim->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        AuditLog::logActivity($reviewer->id, 'catalog_claim_rejected', [
            'claim_id' => $claim->id,
            'artist_id' => $claim->artist_id,
            'claimant_user_id' => $claim->claimant_user_id,
        ]);

        $rejectedClaim = $claim->fresh(['artist', 'claimant', 'reviewer']);
        $rejectedClaim->claimant?->notify(new CatalogClaimStatusNotification($rejectedClaim, CatalogClaimStatusNotification::REJECTED, $reason));

        return $rejectedClaim;
    }
}
