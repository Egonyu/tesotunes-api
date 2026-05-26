<?php

namespace App\Services;

use App\Helpers\StorageHelper;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Models\Artist;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\PlayHistory;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class HomepageService
{
    public function build(?User $user, string $mode = 'all'): array
    {
        $context = $this->buildContext($user);
        $modules = $this->buildModulesForMode($context, $mode);
        [$headline, $subheadline] = $this->resolveHeadlines($context, $mode);

        return [
            'theme' => 'classic_home',
            'audience' => $context['personalized'] ? 'personalized' : 'cold_start',
            'headline' => $headline,
            'subheadline' => $subheadline,
            'chips' => $this->chips($mode),
            'modules' => $modules->values()->all(),
        ];
    }

    private function buildModulesForMode(array $context, string $mode): Collection
    {
        $candidates = match ($mode) {
            'music' => [
                $this->buildHeroModule($context, $mode),
                $this->buildQuickPicksModule($context, $mode),
                $this->buildMadeForYouModule($context, $mode),
                $this->buildBecauseYouListenedModule($context, $mode),
                $this->buildRecommendedTodayModule($context, $mode),
                $this->buildNewFromFollowedModule($context, $mode),
            ],
            'radio' => [
                $this->buildHeroModule($context, $mode),
                $this->buildQuickPicksModule($context, $mode),
                $this->buildPopularRadioModule($mode),
                $this->buildEditorialPickModule($context, $mode),
            ],
            'uganda' => [
                $this->buildHeroModule($context, $mode),
                $this->buildQuickPicksModule($context, $mode),
                $this->buildRecommendedTodayModule($context, $mode),
                $this->buildEditorialPickModule($context, $mode),
                $this->buildEcosystemSpotlightModule($mode),
            ],
            'fresh' => [
                $this->buildHeroModule($context, $mode),
                $this->buildQuickPicksModule($context, $mode),
                $this->buildRecommendedTodayModule($context, $mode),
                $this->buildNewFromFollowedModule($context, $mode),
                $this->buildPopularRadioModule($mode),
            ],
            default => [
                $this->buildHeroModule($context, $mode),
                $this->buildQuickPicksModule($context, $mode),
                $this->buildRecentlyPlayedModule($context, $mode),
                $this->buildMadeForYouModule($context, $mode),
                $this->buildBecauseYouListenedModule($context, $mode),
                $this->buildRecommendedTodayModule($context, $mode),
                $this->buildPopularRadioModule($mode),
                $this->buildNewFromFollowedModule($context, $mode),
                $this->buildEditorialPickModule($context, $mode),
                $this->buildEcosystemSpotlightModule($mode),
            ],
        };

        return collect($candidates)->filter();
    }

    private function resolveHeadlines(array $context, string $mode): array
    {
        return match ($mode) {
            'music' => [
                'Music-first recommendations, without the noise.',
                'A tighter mix of songs, artists, and releases ranked around your strongest listening signals.',
            ],
            'radio' => [
                'Stations, mixes, and lean-back listening.',
                'Featured radio-style playlists and momentum-based sets for a more passive listening session.',
            ],
            'uganda' => [
                'Uganda on repeat.',
                'Regional momentum, local editorial picks, and East African context turned up for this session.',
            ],
            'fresh' => [
                'Fresh drops for your next session.',
                'Recent releases, follow-graph updates, and newer momentum signals weighted above catalog familiarity.',
            ],
            default => [
                $context['personalized']
                    ? 'Your Tesotunes pulse is ready.'
                    : 'Start with what East Africa is feeling right now.',
                $context['personalized']
                    ? 'Fresh picks shaped by your recent listening, artists you follow, and what is breaking across the region.'
                    : 'We are blending regional momentum, editorial moments, and live discovery until your listening profile gets warmer.',
            ],
        };
    }

    private function chips(string $mode): array
    {
        return collect([
            ['id' => 'all', 'label' => 'All'],
            ['id' => 'music', 'label' => 'Music'],
            ['id' => 'radio', 'label' => 'Radio'],
            ['id' => 'uganda', 'label' => 'Uganda'],
            ['id' => 'fresh', 'label' => 'Fresh picks'],
        ])->map(fn (array $chip) => [
            ...$chip,
            'active' => $chip['id'] === $mode,
        ])->all();
    }

    private function buildContext(?User $user): array
    {
        $recentSongs = collect();
        $recentArtist = null;
        $followedArtists = collect();
        $likedSongs = collect();
        $genreIds = collect();

        if ($user) {
            $recentSongs = PlayHistory::query()
                ->where('user_id', $user->id)
                ->whereNotNull('song_id')
                ->with(['song.artist', 'song.album', 'song.primaryGenre'])
                ->latest('played_at')
                ->limit(18)
                ->get()
                ->map(fn (PlayHistory $history) => $history->song)
                ->filter()
                ->unique('id')
                ->values();

            $recentArtist = $recentSongs
                ->map(fn (Song $song) => $song->artist)
                ->filter()
                ->unique('id')
                ->first();

            $followedArtists = Artist::query()
                ->with('user')
                ->whereIn('id', $user->followedArtistIds())
                ->whereIn('status', Artist::VISIBLE_STATUSES)
                ->limit(8)
                ->get();

            $likedSongs = $user->likedSongs()
                ->with(['artist', 'album', 'primaryGenre'])
                ->limit(12)
                ->get();

            $genreIds = $recentSongs
                ->pluck('primary_genre_id')
                ->filter()
                ->merge($likedSongs->pluck('primary_genre_id')->filter())
                ->countBy()
                ->sortDesc()
                ->keys()
                ->take(3)
                ->values();
        }

        return [
            'user' => $user,
            'personalized' => $recentSongs->isNotEmpty() || $likedSongs->isNotEmpty() || $followedArtists->isNotEmpty(),
            'recent_songs' => $recentSongs,
            'recent_song_ids' => $recentSongs->pluck('id')->filter()->values(),
            'recent_artist' => $recentArtist,
            'followed_artists' => $followedArtists,
            'followed_artist_ids' => $followedArtists->pluck('id')->filter()->values(),
            'liked_songs' => $likedSongs,
            'genre_ids' => $genreIds,
        ];
    }

    private function buildHeroModule(array $context, string $mode = 'all'): ?array
    {
        $items = collect();

        $featuredSongs = $this->songQueryForMode($mode)
            ->when($mode !== 'radio', fn ($query) => $query->where('is_featured', true))
            ->orderByDesc($mode === 'fresh' ? 'created_at' : 'play_count')
            ->limit(2)
            ->get();

        foreach ($featuredSongs as $song) {
            $items->push($this->songItem($song, 'Feature', 'Featured momentum across Tesotunes'));
        }

        if ($context['recent_artist'] instanceof Artist) {
            $items->push($this->artistItem($context['recent_artist'], 'Artist focus', 'One of your strongest repeat signals'));
        }

        $editorialPlaylists = $this->featuredPlaylistsQuery()
            ->when($mode === 'radio', fn ($query) => $query->orderByDesc('play_count'))
            ->limit(2)
            ->get();
        foreach ($editorialPlaylists as $playlist) {
            $items->push($this->playlistItem($playlist, 'Curated', 'Built to keep the next session moving'));
        }

        $heroItems = $items->unique(fn (array $item) => $item['entity_type'].'-'.$item['id'])->take(4)->values();

        if ($heroItems->isEmpty()) {
            return null;
        }

        return $this->module(
            'hero-feature',
            'hero_feature',
            'main',
            match ($mode) {
                'radio' => 'Lean back into station mode',
                'uganda' => 'Local momentum worth opening first',
                'fresh' => 'Fresh drops and quick-turn standouts',
                default => 'Open the session with something worth staying for',
            },
            match ($mode) {
                'radio' => 'Station-led picks and curation designed for a lean-back session.',
                'uganda' => 'Lead picks with a stronger Uganda and East Africa tilt.',
                'fresh' => 'Lead picks weighted toward recency and new release energy.',
                default => 'Lead picks shaped by curation, repeat behavior, and what is moving in the region.',
            },
            $heroItems,
            'hero',
            null,
            'Hybrid score: editorial boost + user affinity + regional momentum'
        );
    }

    private function buildQuickPicksModule(array $context, string $mode = 'all'): ?array
    {
        $items = collect();

        foreach ($context['recent_songs']->take(3) as $song) {
            if ($song instanceof Song) {
                $items->push($this->songItem($song, 'Pick up where you left off', 'Recent listening'));
            }
        }

        foreach ($context['followed_artists']->take(2) as $artist) {
            $items->push($this->artistItem($artist, 'Following', 'Artists you already care about'));
        }

        if ($items->isEmpty()) {
            $fallbackSongs = $this->trendingSongs(4, $mode);
            foreach ($fallbackSongs as $song) {
                $items->push($this->songItem($song, 'Trending now', 'A fast way to seed your homepage profile'));
            }
        }

        if ($items->isEmpty()) {
            return null;
        }

        return $this->module(
            'quick-picks',
            'quick_picks',
            'left',
            'Quick picks',
            'Shortcuts into what matters right now.',
            $items->take(5),
            'compact'
        );
    }

    private function buildRecentlyPlayedModule(array $context, string $mode = 'all'): ?array
    {
        $songs = $context['recent_songs'];

        if ($songs->isEmpty()) {
            $songs = $this->trendingSongs(6, $mode);
        }

        if ($songs->isEmpty()) {
            return null;
        }

        return $this->module(
            'recently-played',
            'recently_played',
            'main',
            $context['personalized'] ? 'Recently played' : 'Start here',
            $context['personalized']
                ? 'A quick path back into the songs that already have context for you.'
                : 'Trending songs that help seed the next wave of recommendations.',
            $songs->take(8)->map(fn (Song $song) => $this->songItem($song, 'Recent session', 'Listening history'))->values(),
            'square',
            '/history'
        );
    }

    private function buildMadeForYouModule(array $context, string $mode = 'all'): ?array
    {
        $items = collect();
        $artistIds = $context['followed_artist_ids'];

        if ($artistIds->isNotEmpty()) {
            $artistSongs = $this->songQueryForMode($mode)
                ->whereIn('artist_id', $artistIds)
                ->whereNotIn('id', $context['recent_song_ids'])
                ->orderByDesc('created_at')
                ->limit(4)
                ->get();

            foreach ($artistSongs as $song) {
                $items->push($this->songItem($song, 'From artists you follow', 'Follow graph signal'));
            }
        }

        if ($context['genre_ids']->isNotEmpty()) {
            $genreSongs = $this->songQueryForMode($mode)
                ->whereIn('primary_genre_id', $context['genre_ids'])
                ->whereNotIn('id', $context['recent_song_ids'])
                ->orderByDesc('play_count')
                ->limit(6)
                ->get();

            foreach ($genreSongs as $song) {
                $items->push($this->songItem($song, 'Your sound lane', 'Genre affinity'));
            }
        }

        foreach ($context['followed_artists']->take(3) as $artist) {
            $items->push($this->artistItem($artist, 'Stay close', 'You follow this artist'));
        }

        $items = $items->unique(fn (array $item) => $item['entity_type'].'-'.$item['id'])->take(8)->values();

        if ($items->isEmpty()) {
            return null;
        }

        return $this->module(
            'made-for-you',
            'made_for_you',
            'main',
            'Made for you',
            'The first mix of follows, genre pull, and recent replay patterns.',
            $items,
            'square'
        );
    }

    private function buildBecauseYouListenedModule(array $context, string $mode = 'all'): ?array
    {
        $recentArtist = $context['recent_artist'];

        if (! $recentArtist instanceof Artist) {
            return null;
        }

        $songs = $this->songQueryForMode($mode)
            ->where('artist_id', $recentArtist->id)
            ->whereNotIn('id', $context['recent_song_ids'])
            ->orderByDesc('play_count')
            ->limit(8)
            ->get();

        if ($songs->isEmpty()) {
            return null;
        }

        return $this->module(
            'because-you-listened',
            'because_you_listened',
            'main',
            'Because you listened to '.$recentArtist->stage_name,
            'We are leaning into your strongest repeat artist signal.',
            $songs->map(fn (Song $song) => $this->songItem($song, 'Because you listened', $recentArtist->stage_name))->values(),
            'square'
        );
    }

    private function buildRecommendedTodayModule(array $context, string $mode = 'all'): ?array
    {
        $candidates = $this->songQueryForMode($mode)
            ->limit(40)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $songs = $candidates
            ->map(function (Song $song) use ($context, $mode) {
                $score = 0;
                $score += min((int) $song->play_count, 150000) / 1500;
                $score += min((int) $song->like_count, 25000) / 500;
                $score += $song->is_featured ? 12 : 0;
                $score += now()->diffInDays($song->created_at) <= 14 ? 8 : 0;
                $score += $context['genre_ids']->contains($song->primary_genre_id) ? 16 : 0;
                $score += $context['followed_artist_ids']->contains($song->artist_id) ? 20 : 0;
                $score -= $context['recent_song_ids']->contains($song->id) ? 18 : 0;
                $score += match ($mode) {
                    'fresh' => now()->diffInDays($song->created_at) <= 7 ? 18 : 0,
                    'uganda' => $this->songFeelsRegional($song) ? 14 : 0,
                    'music' => 6,
                    default => 0,
                };

                return [
                    'song' => $song,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->pluck('song')
            ->unique('id')
            ->take(8)
            ->values();

        return $this->module(
            'recommended-today',
            'recommended_today',
            'main',
            'Recommended for today',
            'Hybrid ranking across likes, plays, follow graph, freshness, and regional heat.',
            $songs->map(fn (Song $song) => $this->songItem($song, 'Today pick', 'Hybrid score'))->values(),
            'square'
        );
    }

    private function buildPopularRadioModule(string $mode = 'all'): ?array
    {
        $stations = $this->featuredPlaylistsQuery()
            ->when($mode === 'fresh', fn ($query) => $query->orderByDesc('updated_at'))
            ->limit(6)
            ->get();

        if ($stations->isEmpty()) {
            return null;
        }

        return $this->module(
            'popular-radio',
            'popular_radio',
            'main',
            'Popular radio',
            'Featured stations and high-rotation playlists with strong playback momentum.',
            $stations->map(fn (Playlist $playlist) => $this->playlistItem($playlist, 'Radio', 'Station and playlist momentum'))->values(),
            'square',
            '/radio'
        );
    }

    private function buildNewFromFollowedModule(array $context, string $mode = 'all'): ?array
    {
        $artistIds = $context['followed_artist_ids'];

        if ($artistIds->isEmpty()) {
            return null;
        }

        $songs = $this->songQueryForMode($mode)
            ->whereIn('artist_id', $artistIds)
            ->orderByDesc('created_at')
            ->limit(4)
            ->get();

        if ($songs->isEmpty()) {
            return null;
        }

        return $this->module(
            'new-from-followed',
            'new_from_followed',
            'right',
            'New from artists you follow',
            'Fresh catalog movement from your existing follow graph.',
            $songs->map(fn (Song $song) => $this->songItem($song, 'Fresh drop', 'Artist follow signal'))->values(),
            'compact'
        );
    }

    private function buildEditorialPickModule(array $context, string $mode = 'all'): ?array
    {
        $items = collect();

        if (Schema::hasTable('featured_content')) {
            $editorialItems = FeaturedContent::query()
                ->active()
                ->live()
                ->with(['song.artist', 'song.album', 'artist', 'playlist.owner'])
                ->orderBy('sort_order')
                ->limit(4)
                ->get();

            foreach ($editorialItems as $item) {
                if ($item->song) {
                    $items->push($this->songItem($item->song, 'Editorial', $item->subtitle ?: 'Featured by the Tesotunes team'));
                } elseif ($item->artist) {
                    $items->push($this->artistItem($item->artist, 'Editorial', $item->subtitle ?: 'Featured artist spotlight'));
                } elseif ($item->playlist) {
                    $items->push($this->playlistItem($item->playlist, 'Editorial', $item->subtitle ?: 'Featured playlist'));
                }
            }
        }

        if ($items->isEmpty()) {
            foreach ($this->trendingSongs(4, $mode) as $song) {
                $items->push($this->songItem($song, 'Editors are watching', 'Regional standout'));
            }
        }

        if ($items->isEmpty()) {
            return null;
        }

        return $this->module(
            'editorial-pick',
            'editorial_pick',
            'right',
            'Editor picks',
            'A lighter human-curated layer on top of the recommendation feed.',
            $items->unique(fn (array $item) => $item['entity_type'].'-'.$item['id'])->take(4)->values(),
            'compact'
        );
    }

    private function buildEcosystemSpotlightModule(string $mode = 'all'): ?array
    {
        if ($mode === 'music' || $mode === 'radio') {
            return null;
        }

        $events = Event::query()
            ->published()
            ->upcoming()
            ->orderBy('starts_at')
            ->limit(4)
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        return $this->module(
            'ecosystem-spotlight',
            'ecosystem_spotlight',
            'right',
            'Around the platform',
            'Live events and ecosystem moments, injected sparingly into the music-first homepage.',
            $events->map(fn (Event $event) => $this->eventItem($event, 'Live', 'Upcoming event spotlight'))->values(),
            'compact',
            '/events'
        );
    }

    private function module(
        string $id,
        string $type,
        string $placement,
        string $title,
        ?string $subtitle,
        Collection $items,
        string $itemStyle = 'square',
        ?string $viewAllHref = null,
        ?string $explanation = null,
    ): ?array {
        $resolvedItems = $items->filter()->values();

        if ($resolvedItems->isEmpty()) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'placement' => $placement,
            'title' => $title,
            'subtitle' => $subtitle,
            'explanation' => $explanation,
            'view_all_href' => $viewAllHref,
            'item_style' => $itemStyle,
            'items' => $resolvedItems->all(),
        ];
    }

    private function baseSongQuery()
    {
        return Song::query()
            ->with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->where('visibility', 'public')
            ->whereHas('artist', fn ($query) => $query->whereIn('status', Artist::VISIBLE_STATUSES));
    }

    private function songQueryForMode(string $mode)
    {
        return $this->baseSongQuery()
            ->when($mode === 'fresh', fn ($query) => $query->where('created_at', '>=', now()->subDays(45)))
            ->when($mode === 'uganda', function ($query) {
                $query->where(function ($regional) {
                    $regional
                        ->whereHas('artist', function ($artistQuery) {
                            $artistQuery
                                ->where('country', 'Uganda')
                                ->orWhere('city', 'Kampala');
                        })
                        ->orWhereHas('primaryGenre', function ($genreQuery) {
                            $genreQuery->whereIn('slug', ['afrobeats', 'gospel', 'hip-hop', 'dancehall']);
                        });
                });
            });
    }

    private function featuredPlaylistsQuery()
    {
        return Playlist::query()
            ->with('owner')
            ->where('visibility', 'public')
            ->where('is_featured', true)
            ->orderByDesc('play_count');
    }

    private function trendingSongs(int $limit, string $mode = 'all'): Collection
    {
        return $this->songQueryForMode($mode)
            ->orderByDesc('play_count')
            ->limit($limit)
            ->get();
    }

    private function songFeelsRegional(Song $song): bool
    {
        $artistCountry = strtolower((string) ($song->artist?->country ?? ''));
        $artistCity = strtolower((string) ($song->artist?->city ?? ''));
        $genreSlug = strtolower((string) ($song->primaryGenre?->slug ?? ''));

        return in_array($artistCountry, ['uganda', 'kenya', 'tanzania'], true)
            || in_array($artistCity, ['kampala', 'jinja', 'mbale', 'nairobi', 'dar es salaam'], true)
            || in_array($genreSlug, ['afrobeats', 'gospel', 'dancehall', 'hip-hop'], true);
    }

    private function songItem(Song $song, ?string $eyebrow = null, ?string $reason = null): array
    {
        $resource = SongResource::make($song)->resolve();

        return [
            'id' => $song->id,
            'entity_type' => 'song',
            'title' => $song->title,
            'subtitle' => trim(($song->artist?->stage_name ?? 'Unknown Artist').' · '.number_format((int) ($song->play_count ?? 0)).' plays'),
            'eyebrow' => $eyebrow,
            'reason' => $reason,
            'href' => '/songs/'.($song->slug ?: $song->id),
            'image_url' => $resource['artwork_url'] ?? null,
            'accent' => '#e5ff52',
            'song' => $resource,
        ];
    }

    private function artistItem(Artist $artist, ?string $eyebrow = null, ?string $reason = null): array
    {
        return [
            'id' => $artist->id,
            'entity_type' => 'artist',
            'title' => $artist->stage_name,
            'subtitle' => trim(number_format((int) ($artist->followers_count ?? 0)).' followers'),
            'eyebrow' => $eyebrow,
            'reason' => $reason,
            'href' => '/artists/'.($artist->slug ?: $artist->id),
            'image_url' => $artist->avatar_url ?: $artist->banner_url,
            'artist' => [
                'id' => $artist->id,
                'name' => $artist->stage_name,
                'slug' => $artist->slug,
                'bio' => $artist->bio,
                'avatar_url' => $artist->avatar_url,
                'banner_url' => $artist->banner_url,
                'follower_count' => (int) ($artist->followers_count ?? 0),
                'monthly_listeners' => (int) ($artist->monthly_listeners ?? 0),
                'is_verified' => (bool) $artist->is_verified,
                'genres' => [],
                'status' => $artist->status,
            ],
        ];
    }

    private function playlistItem(Playlist $playlist, ?string $eyebrow = null, ?string $reason = null): array
    {
        $resource = PlaylistResource::make($playlist)->resolve();

        return [
            'id' => $playlist->id,
            'entity_type' => 'playlist',
            'title' => $playlist->name,
            'subtitle' => $playlist->description ?: trim(number_format((int) ($playlist->followers_count ?? 0)).' followers'),
            'eyebrow' => $eyebrow,
            'reason' => $reason,
            'href' => '/playlists/'.($playlist->slug ?: $playlist->id),
            'image_url' => $playlist->artwork_url,
            'playlist' => $resource,
        ];
    }

    private function eventItem(Event $event, ?string $eyebrow = null, ?string $reason = null): array
    {
        $city = $event->city ?: data_get($event, 'location.city');
        $date = $event->starts_at?->format('M j');

        return [
            'id' => $event->id,
            'entity_type' => 'event',
            'title' => $event->title,
            'subtitle' => collect([$city, $date])->filter()->implode(' · ') ?: 'Upcoming event',
            'eyebrow' => $eyebrow,
            'reason' => $reason,
            'href' => '/events/'.$event->id,
            'image_url' => StorageHelper::artworkUrl($event->artwork) ?? StorageHelper::artworkUrl($event->banner),
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'description' => $event->description,
                'venue' => $event->venue ?? '',
                'location' => $city ?? '',
                'start_date' => $event->starts_at?->toIso8601String(),
                'end_date' => $event->ends_at?->toIso8601String(),
                'image_url' => StorageHelper::artworkUrl($event->artwork) ?? StorageHelper::artworkUrl($event->banner),
                'is_free' => (bool) ($event->is_free ?? false),
                'status' => $event->status,
                'artists' => [],
            ],
        ];
    }
}
