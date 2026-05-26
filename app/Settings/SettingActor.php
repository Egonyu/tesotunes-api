<?php

namespace App\Settings;

/**
 * Process-scoped actor context for setting writes.
 *
 * Resolves "who is changing this setting right now?" without coupling
 * the Setting model boot hooks to the SaccoTableStoreDriver. Both call
 * the same static accessors so audit rows are attributed identically
 * regardless of which table the value lives in.
 */
final class SettingActor
{
    private static ?int $overrideActorId = null;

    private static ?string $reason = null;

    public static function set(?int $userId, ?string $reason = null): void
    {
        self::$overrideActorId = $userId;
        self::$reason = $reason;
    }

    public static function clear(): void
    {
        self::$overrideActorId = null;
        self::$reason = null;
    }

    public static function withActor(?int $userId, callable $callback, ?string $reason = null): mixed
    {
        $prevActor = self::$overrideActorId;
        $prevReason = self::$reason;
        self::$overrideActorId = $userId;
        self::$reason = $reason;

        try {
            return $callback();
        } finally {
            self::$overrideActorId = $prevActor;
            self::$reason = $prevReason;
        }
    }

    public static function currentActorId(): ?int
    {
        if (self::$overrideActorId !== null) {
            return self::$overrideActorId;
        }

        if (! app()->bound('auth')) {
            return null;
        }

        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function currentReason(): ?string
    {
        return self::$reason;
    }

    public static function currentIp(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function currentRole(): ?string
    {
        if (! app()->bound('auth')) {
            return null;
        }

        try {
            return optional(auth()->user())->role;
        } catch (\Throwable) {
            return null;
        }
    }
}
