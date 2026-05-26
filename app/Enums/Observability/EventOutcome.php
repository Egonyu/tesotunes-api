<?php

namespace App\Enums\Observability;

/**
 * Outcome of a security-relevant action.
 *
 *   Success    — the action completed (note: a *successful* attack is the
 *                most dangerous outcome, hence the high scorer weight).
 *   Failed     — the action did not complete (e.g. wrong password).
 *   Blocked    — the platform actively rejected the action (lockout, 403, 429).
 *   Suspicious — completed or partial, but flagged by a heuristic.
 */
enum EventOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Suspicious = 'suspicious';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Blocked => 'Blocked',
            self::Suspicious => 'Suspicious',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
