<?php

namespace App\Enums;

/**
 * Status of an individual KYC document submission.
 *
 *   Pending  — uploaded, awaiting admin review
 *   Verified — admin approved this document
 *   Rejected — admin rejected this document (resubmission required)
 *
 * Historical note: prior to 2026-05-19 the verified state was stored
 * as the literal string 'active' due to a typo in the original constant.
 * The 2026_05_19 normalization migration rewrites all 'active' rows to
 * 'verified' so this enum is the single source of truth going forward.
 */
enum KycDocumentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
