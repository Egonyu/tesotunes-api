<?php

namespace App\Observers;

use App\Models\Album;
use App\Services\ActivityService;

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
