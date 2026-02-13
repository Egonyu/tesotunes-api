<?php

namespace App\Observers;

use App\Models\Playlist;
use App\Services\ActivityService;

class PlaylistObserver
{
    /**
     * Handle the Playlist "created" event.
     */
    public function created(Playlist $playlist): void
    {
        // Only log public playlists
        if ($playlist->is_public && $playlist->user_id) {
            ActivityService::log(
                actor: $playlist->user,
                action: 'created_playlist',
                subject: $playlist,
                metadata: [
                    'playlist_name' => $playlist->name,
                    'playlist_description' => $playlist->description ?? null,
                    'song_count' => $playlist->songs()->count(),
                ]
            );
        }
    }

    /**
     * Handle the Playlist "updated" event.
     */
    public function updated(Playlist $playlist): void
    {
        // Log if playlist visibility changed from private to public
        if ($playlist->isDirty('is_public') && $playlist->is_public && !$playlist->original['is_public']) {
            ActivityService::log(
                actor: $playlist->user,
                action: 'playlist_made_public',
                subject: $playlist,
                metadata: [
                    'playlist_name' => $playlist->name,
                    'song_count' => $playlist->songs()->count(),
                ]
            );
        }
    }
}
