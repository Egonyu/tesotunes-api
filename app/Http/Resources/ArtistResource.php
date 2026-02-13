<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->stage_name,
            'slug' => $this->slug,
            'bio' => $this->bio,

            // Media
            'avatar_url' => $this->avatar ? url('storage/' . $this->avatar) : null,
            'banner_url' => $this->banner ? url('storage/' . $this->banner) : null,

            // Location
            'country' => $this->country,
            'city' => $this->city,

            // Verification
            'is_verified' => (bool) $this->is_verified,
            'verification_badge' => $this->verification_badge,

            // Stats
            'total_plays' => (int) ($this->total_plays ?? 0),
            'total_songs' => (int) ($this->total_songs ?? 0),
            'total_albums' => (int) ($this->total_albums ?? 0),
            'follower_count' => (int) ($this->follower_count ?? 0),

            // Social
            'social_links' => $this->when($this->social_links, $this->social_links),

            // Genre
            'genre' => $this->when($this->relationLoaded('primaryGenre') && $this->primaryGenre, function () {
                return [
                    'id' => $this->primaryGenre->id,
                    'name' => $this->primaryGenre->name,
                    'slug' => $this->primaryGenre->slug,
                ];
            }),

            // Conditional nested resources
            'songs' => SongResource::collection($this->whenLoaded('songs')),
            'albums' => AlbumResource::collection($this->whenLoaded('albums')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => url("/api/artists/{$this->slug}"),
                'songs' => url("/api/artists/{$this->id}/songs"),
                'albums' => url("/api/artists/{$this->id}/albums"),
            ],
        ];
    }
}
