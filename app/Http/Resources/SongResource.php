<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SongResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $streamingAccess = SongStreamingAccessResource::make($this->resource)->resolve($request);
        $resolvedArtworkUrl = $this->artwork_url
            ?? StorageHelper::url($this->artwork)
            ?? (($this->relationLoaded('album') && $this->album)
                ? StorageHelper::url($this->album->artwork)
                : null);
        $songRouteKey = $this->slug ?: $this->id;
        $loadedArtistRouteKey = ($this->relationLoaded('artist') && $this->artist)
            ? ($this->artist->slug ?: $this->artist->id)
            : null;
        $artistRouteKey = $loadedArtistRouteKey
            ?? ($this->artist_slug ?? null)
            ?? ($this->artist_id ?? null);
        $albumRouteKey = ($this->relationLoaded('album') && $this->album)
            ? ($this->album->slug ?: $this->album->id)
            : ($this->album_id ?? null);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,

            // Media
            'artwork_url' => $resolvedArtworkUrl,

            ...$streamingAccess,

            // Metadata
            'status' => $this->status,
            'isrc' => $this->isrc_code,
            'isrc_assignment' => $this->when(
                method_exists($this->resource, 'getIsrcAssignmentSummary'),
                fn () => $this->resource->getIsrcAssignmentSummary()
            ),
            'duration_seconds' => (int) ($this->duration_seconds ?? 0),
            'duration_formatted' => $this->duration_formatted,
            'is_explicit' => (bool) $this->is_explicit,
            'is_featured' => (bool) $this->is_featured,
            'is_free' => (bool) $this->is_free,
            'price' => $this->when($this->price > 0, $this->price),
            'release_date' => $this->release_date,

            // Stats
            'play_count' => (int) ($this->play_count ?? 0),
            'like_count' => (int) ($this->like_count ?? 0),
            'download_count' => (int) ($this->download_count ?? 0),

            // Relationships
            'artist' => $this->when($this->relationLoaded('artist') || $this->artist_name, function () {
                if ($this->relationLoaded('artist') && $this->artist) {
                    return [
                        'id' => $this->artist->id,
                        'user_id' => $this->artist->user_id,
                        'name' => $this->artist->stage_name,
                        'slug' => $this->artist->slug,
                        'avatar_url' => StorageHelper::avatarUrl($this->artist->avatar, $this->artist->stage_name),
                    ];
                }

                // Fallback for raw DB queries
                return [
                    'id' => $this->artist_id ?? null,
                    'name' => $this->artist_name ?? null,
                    'slug' => $this->artist_slug ?? null,
                ];
            }),
            'album' => $this->when($this->relationLoaded('album') && $this->album, function () {
                return [
                    'id' => $this->album->id,
                    'title' => $this->album->title,
                    'slug' => $this->album->slug,
                    'artwork_url' => StorageHelper::url($this->album->artwork),
                ];
            }),
            'genre' => $this->when($this->relationLoaded('primaryGenre') && $this->primaryGenre, function () {
                return [
                    'id' => $this->primaryGenre->id,
                    'name' => $this->primaryGenre->name,
                    'slug' => $this->primaryGenre->slug,
                ];
            }),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => $songRouteKey
                    ? route('api.music.song', ['song' => $songRouteKey])
                    : null,
                'artist' => $artistRouteKey
                    ? route('api.music.artist', ['artist' => $artistRouteKey])
                    : null,
                'album' => $albumRouteKey
                    ? route('api.music.album', ['album' => $albumRouteKey])
                    : null,
            ],

            // Share payload — everything the frontend needs to build a social-share sheet:
            //   • share_url:  the beautiful canonical browser URL (tesotunes.com/songs/slug)
            //   • caption:    pre-filled text for social posts (title · artist · url)
            //   • og_image:   artwork URL for the preview card
            //   • platform_links: ready-to-open deep-link URLs for each platform
            'share' => $this->buildSharePayload(),
        ];
    }

    /**
     * Build the static share payload for this song.
     * Unlike Share::sharePayload() (which is per-share-record),
     * this is included in every SongResource response so the frontend
     * can render a share sheet instantly without a round-trip POST.
     */
    protected function buildSharePayload(): array
    {
        $frontendBase = rtrim(config('app.frontend_url', config('app.url')), '/');
        $slug = $this->slug ?? $this->id;
        $shareUrl = "{$frontendBase}/songs/{$slug}";

        $artistName = null;
        if ($this->relationLoaded('artist') && $this->artist) {
            $artistName = $this->artist->stage_name ?? $this->artist->name;
        }
        $artistName = $artistName ?? $this->artist_name ?? 'TesoTunes';

        $title = "{$this->title} — {$artistName}";
        $description = "Listen to {$this->title} by {$artistName} on TesoTunes";
        $ogImage = $this->artwork_url
            ?? StorageHelper::url($this->artwork)
            ?? (($this->relationLoaded('album') && $this->album)
                ? StorageHelper::url($this->album->artwork)
                : null);

        $encoded = urlencode($shareUrl);
        $encodedTitle = urlencode($title);

        return [
            'share_url' => $shareUrl,
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $ogImage,
            'caption' => "🎵 {$title}\n\n{$description}\n\n{$shareUrl}",
            'platform_links' => [
                'copy' => $shareUrl,
                'whatsapp' => "https://wa.me/?text={$encodedTitle}%20{$encoded}",
                'twitter' => "https://twitter.com/intent/tweet?text={$encodedTitle}&url={$encoded}&hashtags=TesoTunes",
                'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encoded}",
                'telegram' => "https://t.me/share/url?url={$encoded}&text={$encodedTitle}",
                'instagram' => null,
            ],
        ];
    }
}
