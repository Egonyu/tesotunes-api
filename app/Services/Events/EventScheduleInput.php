<?php

namespace App\Services\Events;

use Illuminate\Support\Carbon;

/**
 * Turns the date and time an organiser typed into the instants stored.
 *
 * Organisers type local wall-clock time ("16 Sep, 7:00 PM"). The forms sent it
 * as start_date + start_time and the controllers glued them into a string the
 * database read as UTC — so a 7 PM show was stored as 19:00 UTC, displayed to
 * buyers in Uganda as 10 PM, and its sale windows closed three hours late.
 *
 * Local input is now read in the event's timezone and stored as UTC. Values
 * that already carry an offset (ISO 8601 with Z or +03:00) are respected.
 */
final class EventScheduleInput
{
    public const DEFAULT_TIMEZONE = 'Africa/Kampala';

    /**
     * Resolve starts_at / ends_at on a validated payload, in place.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function apply(array &$validated, ?string $timezone = null): void
    {
        $timezone = self::timezone($validated['timezone'] ?? $timezone);

        $startsAt = self::resolve(
            $validated['starts_at'] ?? null,
            $validated['start_date'] ?? null,
            $validated['start_time'] ?? null,
            '00:00',
            $timezone,
        );
        $endsAt = self::resolve(
            $validated['ends_at'] ?? null,
            $validated['end_date'] ?? null,
            $validated['end_time'] ?? null,
            '23:59',
            $timezone,
        );

        if ($startsAt) {
            $validated['starts_at'] = $startsAt;
        }
        if ($endsAt) {
            $validated['ends_at'] = $endsAt;
        }

        unset($validated['start_date'], $validated['start_time'], $validated['end_date'], $validated['end_time']);
    }

    /**
     * A single local or offset-carrying value, e.g. a tier's sale window.
     */
    public static function instant(mixed $value, ?string $timezone = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        $timezone = self::timezone($timezone);

        return self::hasOffset($value)
            ? Carbon::parse($value)->utc()
            : Carbon::parse($value, $timezone)->utc();
    }

    private static function resolve(mixed $combined, mixed $date, mixed $time, string $defaultTime, string $timezone): ?Carbon
    {
        if ($combined !== null && $combined !== '') {
            return self::instant($combined, $timezone);
        }

        if ($date === null || $date === '') {
            return null;
        }

        $clock = trim((string) ($time ?: $defaultTime));

        return Carbon::parse(substr((string) $date, 0, 10).' '.$clock, $timezone)->utc();
    }

    private static function hasOffset(string $value): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }

    private static function timezone(?string $timezone): string
    {
        return $timezone && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : self::DEFAULT_TIMEZONE;
    }
}
