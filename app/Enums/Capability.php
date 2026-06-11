<?php

namespace App\Enums;

/**
 * Seller-side capabilities a user account can hold. One account may hold
 * any combination; each is granted independently through the same
 * apply -> review -> grant lifecycle and shares the single KYC gate.
 */
enum Capability: string
{
    case Artist = 'artist';
    case Seller = 'seller';
    case Organizer = 'organizer';
    case Promoter = 'promoter';
    case Label = 'label';

    public function label(): string
    {
        return match ($this) {
            self::Artist => 'Artist',
            self::Seller => 'Store seller',
            self::Organizer => 'Event organizer',
            self::Promoter => 'Promoter',
            self::Label => 'Label',
        };
    }
}
