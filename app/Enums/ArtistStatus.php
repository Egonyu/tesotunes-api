<?php

namespace App\Enums;

/**
 * AXIS 2: Artist application status — controls whether a user is allowed
 * to act as an artist on the platform (upload music, access artist dashboard).
 *
 *   Pending   — application submitted, awaiting admin review
 *   Approved  — admin approved; can upload, access dashboard
 *   Rejected  — admin rejected; user may reapply
 *   Suspended — previously approved, now suspended (TOS violation, etc.)
 *
 * This is SEPARATE from KycStatus (identity verification) and from
 * Artist::is_verified (featured/blue-check, the third axis).
 *
 * Note: the underlying `artists.status` column historically used values
 * 'pending', 'active', 'approved', 'verified' interchangeably. The
 * normalization migration rewrites them to the canonical four below.
 */
enum ArtistStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    public function canUpload(): bool
    {
        return $this === self::Approved;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
