<?php

namespace App\Enums;

enum AdPlacement: string
{
    // ── Web placements ────────────────────────────────────────────────────────
    case WebTopBanner = 'web_top_banner';      // Full-width, below header
    case WebSidebarTop = 'web_sidebar_top';     // Top of left sidebar (desktop)
    case WebSidebarBottom = 'web_sidebar_bottom';  // Bottom of left sidebar (desktop)
    case WebInFeed1 = 'web_in_feed_1';       // After 3rd card in home/browse feed
    case WebInFeed2 = 'web_in_feed_2';       // After 8th card in home/browse feed
    case WebPlayerAbove = 'web_player_above';    // Strip above the persistent player bar
    case WebBetweenSongs = 'web_between_songs';   // Audio overlay between tracks
    case WebSongPage = 'web_song_page';       // Song detail page (below meta)
    case WebArtistPage = 'web_artist_page';     // Artist profile page
    case WebSearchInline = 'web_search_inline';   // Within search results list

    // ── Mobile placements ─────────────────────────────────────────────────────
    case MobileHomeBanner = 'mobile_home_banner';    // Top of Home tab
    case MobileHomeInFeed = 'mobile_home_in_feed';   // After 3rd card in Home feed
    case MobileSearchBanner = 'mobile_search_banner';  // Top of Search tab
    case MobileLibraryBanner = 'mobile_library_banner'; // Top of Library tab
    case MobilePlayerAbove = 'mobile_player_above';   // Above the mini-player bar
    case MobileBetweenSongs = 'mobile_between_songs';  // Audio overlay between tracks

    public function label(): string
    {
        return match ($this) {
            self::WebTopBanner => 'Web — Top Banner',
            self::WebSidebarTop => 'Web — Sidebar Top',
            self::WebSidebarBottom => 'Web — Sidebar Bottom',
            self::WebInFeed1 => 'Web — In-Feed (position 1)',
            self::WebInFeed2 => 'Web — In-Feed (position 2)',
            self::WebPlayerAbove => 'Web — Above Player Bar',
            self::WebBetweenSongs => 'Web — Between Songs (Audio)',
            self::WebSongPage => 'Web — Song Detail Page',
            self::WebArtistPage => 'Web — Artist Profile Page',
            self::WebSearchInline => 'Web — Search Results Inline',
            self::MobileHomeBanner => 'Mobile — Home Tab Banner',
            self::MobileHomeInFeed => 'Mobile — Home In-Feed',
            self::MobileSearchBanner => 'Mobile — Search Tab Banner',
            self::MobileLibraryBanner => 'Mobile — Library Tab Banner',
            self::MobilePlayerAbove => 'Mobile — Above Mini-Player',
            self::MobileBetweenSongs => 'Mobile — Between Songs (Audio)',
        };
    }

    public function device(): string
    {
        return str_starts_with($this->value, 'web_') ? 'desktop' : 'mobile';
    }

    public function isAudio(): bool
    {
        return in_array($this, [self::WebBetweenSongs, self::MobileBetweenSongs]);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
