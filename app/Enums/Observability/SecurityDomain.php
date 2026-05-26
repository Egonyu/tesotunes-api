<?php

namespace App\Enums\Observability;

/**
 * Top-level security domain an observability event belongs to.
 *
 * These map 1:1 to the `observability_events.domain` column and to the
 * security-console dashboard sections.
 */
enum SecurityDomain: string
{
    case Auth = 'auth';
    case Payments = 'payments';
    case Api = 'api';
    case Integrity = 'integrity';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Authentication & Identity',
            self::Payments => 'Payments & Fraud',
            self::Api => 'API Abuse & Bots',
            self::Integrity => 'Integrity & Insider',
            self::System => 'System & Infrastructure',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
