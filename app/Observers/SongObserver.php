<?php

namespace App\Observers;

use App\Models\Song;
use App\Services\ActivityService;

class SongObserver
{
    /**
     * Handle the Song "created" event.
     */
    public function created(Song $song): void
    {
        // Only log activity if song is approved/published
        if ($song->status === 'approved' && $song->artist && $song->artist->user) {
            ActivityService::log(
                actor: $song->artist->user,
                action: 'uploaded_song',
                subject: $song,
                metadata: [
                    'song_title' => $song->title,
                    'artist_name' => $song->artist->stage_name,
                    'genre' => $song->primaryGenre?->name,
                ],
                actorType: 'Artist'
            );
        }
    }

    /**
     * Handle the Song "updated" event.
     */
    public function updated(Song $song): void
    {
        // Log activity when song gets approved
        if ($song->isDirty('status') && $song->status === 'approved') {
            if ($song->artist && $song->artist->user) {
                ActivityService::log(
                    actor: $song->artist->user,
                    action: 'uploaded_song',
                    subject: $song,
                    metadata: [
                        'song_title' => $song->title,
                        'artist_name' => $song->artist->stage_name ?? $song->artist->name,
                        'genre' => $song->genre?->name,
                    ],
                    actorType: 'Artist'
                );
            }
        }

        // Log when song is distributed
        if ($song->isDirty('distribution_status') && $song->distribution_status === 'distributed') {
            if ($song->artist && $song->artist->user) {
                ActivityService::log(
                    actor: $song->artist->user,
                    action: 'distributed_song',
                    subject: $song,
                    metadata: [
                        'song_title' => $song->title,
                        'platforms' => $song->distribution_platforms ?? [],
                    ],
                    actorType: 'Artist'
                );
            }
        }

        // Log when song gets featured
        if ($song->isDirty('is_featured') && $song->is_featured) {
            if ($song->artist && $song->artist->user) {
                ActivityService::log(
                    actor: $song->artist->user,
                    action: 'featured_song',
                    subject: $song,
                    metadata: [
                        'song_title' => $song->title,
                        'artist_name' => $song->artist->stage_name ?? $song->artist->name,
                    ],
                    actorType: 'Artist'
                );
            }
        }
    }

    /**
     * Handle the Song "deleted" event.
     */
    public function deleted(Song $song): void
    {
        // Optionally remove related activities
        // Activity::where('subject_type', Song::class)
        //     ->where('subject_id', $song->id)
        //     ->delete();
    }

    /**
     * Handle the Song "restored" event.
     */
    public function restored(Song $song): void
    {
        //
    }

    /**
     * Handle the Song "force deleted" event.
     */
    public function forceDeleted(Song $song): void
    {
        // Clean up activities
        // Activity::where('subject_type', Song::class)
        //     ->where('subject_id', $song->id)
        //     ->delete();
    }
}
