<?php

namespace App\Enums;

/**
 * AXIS 1: Identity (KYC) status for a user.
 *
 * Lifecycle:
 *   None → Partial → PendingReview → Verified → (Expired | Rejected → Partial → …)
 *
 *   None          — nothing collected yet (default for fresh accounts)
 *   Partial       — some identifying signal collected (phone verified), no docs yet
 *   PendingReview — documents submitted, awaiting admin review
 *   Verified      — admin approved; user is eligible for KYC-gated actions
 *   Rejected      — admin rejected the submission; user may resubmit
 *   Expired       — verification aged out (annual policy); re-KYC required
 *
 * Only KycService writes to users.kyc_status.
 */
enum KycStatus: string
{
    case None = 'none';
    case Partial = 'partial';
    case PendingReview = 'pending_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not started',
            self::Partial => 'In progress',
            self::PendingReview => 'Under review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether this status grants access to KYC-gated actions
     * (withdrawals, music claiming, payouts, disputes).
     */
    public function isEligibleForSensitiveActions(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether the user can submit (or resubmit) KYC documents
     * from this state.
     */
    public function canSubmitDocuments(): bool
    {
        return in_array($this, [
            self::None,
            self::Partial,
            self::Rejected,
            self::Expired,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
