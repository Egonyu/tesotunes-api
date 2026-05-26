<?php

namespace App\Enums\Observability;

/**
 * Severity of a security-relevant event. `weight()` feeds the risk scorer;
 * `rank()` powers ordering and `atLeast()` threshold checks.
 */
enum EventSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /**
     * Base contribution to the 0-100 risk score.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 10,
            self::Medium => 25,
            self::High => 45,
            self::Critical => 65,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
