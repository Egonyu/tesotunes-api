<?php

namespace App\Observers;

use App\Models\Playlist;
use App\Services\ActivityService;
use App\Services\FeedItemService;

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

            FeedItemService::create([
                'type' => 'playlist_created',
                'module' => 'music',
                'title' => ($playlist->user->name ?? 'Someone').' created a playlist: '.$playlist->name,
                'body' => $playlist->description ? substr($playlist->description, 0, 200) : null,
                'actor_id' => $playlist->user_id,
                'actor_type' => 'user',
                'actor_name' => $playlist->user->name,
                'actor_avatar_url' => $playlist->user->avatar_url,
                'subject_type' => Playlist::class,
                'subject_id' => $playlist->id,
                'media_type' => 'image',
                'media_url' => $playlist->artwork_url,
                'actions' => [
                    ['type' => 'view', 'label' => 'View Playlist', 'url' => "/playlists/{$playlist->slug}"],
                ],
                'extras' => [
                    'song_count' => $playlist->songs()->count(),
                ],
            ]);
        }
    }

    /**
     * Handle the Playlist "updated" event.
     */
    public function updated(Playlist $playlist): void
    {
        // Log if playlist visibility changed from private to public
        if ($playlist->isDirty('is_public') && $playlist->is_public && ! $playlist->original['is_public']) {
            ActivityService::log(
                actor: $playlist->user,
                action: 'playlist_made_public',
                subject: $playlist,
                metadata: [
                    'playlist_name' => $playlist->name,
                    'song_count' => $playlist->songs()->count(),
                ]
            );

            FeedItemService::create([
                'type' => 'playlist_created',
                'module' => 'music',
                'title' => ($playlist->user->name ?? 'Someone').' made a playlist public: '.$playlist->name,
                'actor_id' => $playlist->user_id,
                'actor_type' => 'user',
                'actor_name' => $playlist->user->name,
                'actor_avatar_url' => $playlist->user->avatar_url,
                'subject_type' => Playlist::class,
                'subject_id' => $playlist->id,
                'actions' => [
                    ['type' => 'view', 'label' => 'View Playlist', 'url' => "/playlists/{$playlist->slug}"],
                ],
            ]);
        }
    }
}
