<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SongResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,

            // Media
            'artwork_url' => $this->artwork_url ?? ($this->artwork ? url('storage/'.$this->artwork) : null),
            'audio_url' => $this->when(
                $this->canStreamFor($request->user()),
                fn () => $this->audio_file_320 ? url('storage/'.$this->audio_file_320) : null
            ),

            // Metadata
            'duration_seconds' => $this->duration_seconds,
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
                        'name' => $this->artist->stage_name,
                        'slug' => $this->artist->slug,
                        'avatar_url' => $this->artist->avatar ? url('storage/'.$this->artist->avatar) : null,
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
                    'artwork_url' => $this->album->artwork ? url('storage/'.$this->album->artwork) : null,
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
                'self' => url("/api/songs/{$this->slug}"),
                'artist' => $this->artist_id ? url("/api/artists/{$this->artist_id}") : null,
                'album' => $this->album_id ? url("/api/albums/{$this->album_id}") : null,
            ],
        ];
    }

    /**
     * Check if the current user can stream this song.
     */
    protected function canStreamFor(?object $user): bool
    {
        if ($this->is_free) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if (isset($user->subscription_tier) && $user->subscription_tier === 'premium') {
            return true;
        }

        return false;
    }
}
