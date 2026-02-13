<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PodcastResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'summary' => $this->summary,
            'language' => $this->language,
            'explicit_content' => $this->explicit_content,
            'cover_image_url' => $this->cover_image_url,
            'rss_feed_url' => $this->rss_feed_url,
            'status' => $this->status,
            'is_premium' => $this->is_premium,
            'author_name' => $this->author_name,
            'email' => $this->when($this->isOwnedBy($request->user()), $this->email),
            'copyright' => $this->copyright,
            'website_url' => $this->website_url,
            'tags' => $this->tags ?? [],
            
            // Statistics
            'total_episodes' => $this->total_episodes,
            'total_listens' => $this->total_listen_count,
            'total_subscribers' => $this->subscriber_count,
            
            // Relationships
            'creator' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'itunes_id' => $this->category->itunes_id,
            ],
            'subcategory' => $this->when($this->subcategory, function () {
                return [
                    'id' => $this->subcategory->id,
                    'name' => $this->subcategory->name,
                ];
            }),
            
            // Platform links
            'spotify_url' => $this->spotify_url,
            'apple_podcasts_url' => $this->apple_podcasts_url,
            'google_podcasts_url' => $this->google_podcasts_url,
            
            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            
            // API URLs
            'links' => [
                'self' => route('api.podcast.show', $this->uuid),
                'episodes' => route('api.podcast.episodes', $this->uuid),
                'rss' => route('api.podcast.rss', $this->uuid),
                'web' => route('podcast.show', $this->slug),
            ],
        ];
    }
}
