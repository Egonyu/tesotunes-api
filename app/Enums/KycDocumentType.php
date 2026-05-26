<?php

namespace App\Enums;

/**
 * Types of KYC documents we collect from users.
 *
 * The three required documents for identity verification are:
 *   - National ID front
 *   - National ID back
 *   - Selfie with ID held next to the face
 */
enum KycDocumentType: string
{
    case NationalIdFront = 'national_id_front';
    case NationalIdBack = 'national_id_back';
    case SelfieWithId = 'selfie_with_id';

    public function label(): string
    {
        return match ($this) {
            self::NationalIdFront => 'National ID (front)',
            self::NationalIdBack => 'National ID (back)',
            self::SelfieWithId => 'Selfie holding ID',
        };
    }

    /**
     * Documents required for a complete KYC submission.
     *
     * @return list<self>
     */
    public static function required(): array
    {
        return [self::NationalIdFront, self::NationalIdBack, self::SelfieWithId];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
