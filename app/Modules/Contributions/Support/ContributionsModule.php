<?php

namespace App\Modules\Contributions\Support;

use App\Helpers\CacheHelper;
use App\Models\Setting;

/**
 * Runtime on/off control for the Ateso corpus module. The flags live in
 * Settings (not just env) so an admin can flip the whole feature — and the
 * Edula "Earn" cards — from the panel without a deploy. The config values are
 * the defaults until an admin sets them.
 */
class ContributionsModule
{
    public const SETTING_ENABLED = 'contributions_enabled';

    public const SETTING_FEED_CARDS = 'contributions_feed_cards_enabled';

    public static function enabled(): bool
    {
        return self::flag(self::SETTING_ENABLED, (bool) config('contributions.enabled', false));
    }

    /**
     * Earn cards only show when the module is on AND the cards toggle is on.
     */
    public static function feedCardsEnabled(): bool
    {
        return self::enabled()
            && self::flag(self::SETTING_FEED_CARDS, (bool) config('contributions.feed.enabled', true));
    }

    public static function setEnabled(bool $value): void
    {
        Setting::set(self::SETTING_ENABLED, $value, 'boolean', 'contributions');
        self::bust();
    }

    public static function setFeedCardsEnabled(bool $value): void
    {
        Setting::set(self::SETTING_FEED_CARDS, $value, 'boolean', 'contributions');
        self::bust();
    }

    private static function flag(string $key, bool $default): bool
    {
        $value = Setting::get($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function bust(): void
    {
        CacheHelper::flush(['settings']);
    }
}
