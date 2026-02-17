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
        // Load profile relation for location data if available
        $profile = $this->whenLoaded('profile', fn() => $this->profile);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->stage_name,
            'slug' => $this->slug,
            'bio' => $this->bio,

            // Media
            'avatar_url' => $this->avatar_url,
            'banner_url' => $this->cover_image ? url('storage/' . $this->cover_image) : null,
            'banner' => $this->cover_image ? url('storage/' . $this->cover_image) : null,
            'cover_image' => $this->cover_image ? url('storage/' . $this->cover_image) : null,

            // Location — sourced from profile relation or artist attributes
            'country' => $this->country ?? ($this->relationLoaded('profile') && $this->profile ? ($this->profile->country ?? $this->profile->location ?? null) : null),
            'city' => $this->city ?? ($this->relationLoaded('profile') && $this->profile ? ($this->profile->city ?? null) : null),

            // Verification
            'is_verified' => (bool) $this->is_verified,
            'verification_badge' => $this->is_verified ? 'verified' : null,
            'verification_status' => $this->verification_status ?? 'pending',

            // Stats
            'total_plays' => (int) ($this->total_plays_count ?? 0),
            'total_songs' => (int) ($this->total_songs_count ?? 0),
            'total_albums' => (int) ($this->total_albums_count ?? 0),
            'follower_count' => (int) ($this->followers_count ?? 0),

            // Social & Links
            'social_links' => $this->when($this->social_links, $this->social_links),
            'website_url' => $this->website_url,

            // Career / Meta
            'career_start_year' => $this->career_start_year,
            'record_label' => $this->record_label,
            'influences' => $this->influences,

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
