<?php

namespace App\Enums;

enum CapabilityStatus: string
{
    case Pending = 'pending';
    case Granted = 'granted';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function isActive(): bool
    {
        return $this === self::Granted;
    }

    public function allowsReapplication(): bool
    {
        return in_array($this, [self::Rejected, self::Revoked], true);
    }
}
