<?php

namespace App\Observers;

use App\Models\Album;
use App\Services\ActivityService;
use App\Services\FeedItemService;

class AlbumObserver
{
    /**
     * Handle the Album "created" event.
     */
    public function created(Album $album): void
    {
        // Log album release activity
        if ($album->user_id) {
            ActivityService::log(
                actor: $album->user,
                action: 'released_album',
                subject: $album,
                metadata: [
                    'album_title' => $album->title,
                    'release_date' => $album->release_date?->toDateTimeString(),
                    'track_count' => $album->songs()->count(),
                ]
            );

            $artist = $album->artist ?? $album->user->artist;
            FeedItemService::create([
                'type' => 'album_release',
                'module' => 'music',
                'title' => ($artist?->stage_name ?? $album->user->name).' dropped a new album: '.$album->title,
                'actor_id' => $album->user_id,
                'actor_type' => 'artist',
                'actor_name' => $artist?->stage_name ?? $album->user->name,
                'actor_avatar_url' => $artist?->avatar_url ?? $album->user->avatar_url,
                'actor_verified' => (bool) ($artist?->is_verified ?? false),
                'subject_type' => Album::class,
                'subject_id' => $album->id,
                'media_type' => 'album',
                'media_url' => $album->artwork_url,
                'has_celebration' => true,
                'actions' => [
                    ['type' => 'view', 'label' => 'View Album', 'url' => "/albums/{$album->slug}"],
                ],
                'extras' => [
                    'album_id' => $album->id,
                    'track_count' => $album->songs()->count(),
                ],
            ]);
        }
    }

    /**
     * Handle the Album "updated" event.
     */
    public function updated(Album $album): void
    {
        // Log when album is published
        if ($album->isDirty('status') && $album->status === 'published' && $album->original['status'] !== 'published') {
            ActivityService::log(
                actor: $album->user,
                action: 'album_published',
                subject: $album,
                metadata: [
                    'album_title' => $album->title,
                    'track_count' => $album->songs()->count(),
                ]
            );
        }
    }
}
