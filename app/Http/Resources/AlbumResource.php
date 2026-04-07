<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlbumResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $resolvedArtworkUrl = $this->artwork_url ?? StorageHelper::url($this->artwork);
        $albumRouteKey = $this->slug ?: $this->id;
        $artistRouteKey = $this->relationLoaded('artist') && $this->artist
            ? ($this->artist->slug ?: $this->artist->id)
            : ($this->artist_id ?? null);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,

            // Media
            'artwork_url' => $resolvedArtworkUrl,

            // Metadata
            'album_type' => $this->album_type,
            'release_date' => $this->release_date?->toIso8601String(),
            'release_year' => $this->release_year,
            'is_explicit' => (bool) $this->is_explicit,
            'is_free' => (bool) $this->is_free,
            'price' => $this->when($this->price > 0, $this->price),
            'record_label' => $this->record_label,
            'copyright_notice' => $this->copyright_notice,

            // Stats
            'total_tracks' => (int) ($this->total_tracks ?? 0),
            'total_duration_seconds' => (int) ($this->total_duration_seconds ?? 0),
            'play_count' => (int) ($this->play_count ?? 0),
            'like_count' => (int) ($this->like_count ?? 0),
            'download_count' => (int) ($this->download_count ?? 0),

            // Relationships
            'artist' => $this->when($this->relationLoaded('artist') && $this->artist, function () {
                return [
                    'id' => $this->artist->id,
                    'name' => $this->artist->stage_name,
                    'slug' => $this->artist->slug,
                    'avatar_url' => StorageHelper::avatarUrl($this->artist->avatar, $this->artist->stage_name),
                ];
            }),
            'genre' => $this->when($this->relationLoaded('primaryGenre') && $this->primaryGenre, function () {
                return [
                    'id' => $this->primaryGenre->id,
                    'name' => $this->primaryGenre->name,
                    'slug' => $this->primaryGenre->slug,
                ];
            }),

            // Conditional nested resources
            'songs' => SongResource::collection($this->whenLoaded('songs')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => $albumRouteKey ? route('api.music.album', ['album' => $albumRouteKey]) : null,
                'tracks' => $albumRouteKey ? route('api.music.album.tracks', ['album' => $albumRouteKey]) : null,
                'artist' => $artistRouteKey ? route('api.music.artist', ['artist' => $artistRouteKey]) : null,
            ],
        ];
    }
}
