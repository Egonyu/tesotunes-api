<?php

namespace App\Observers;

use App\Models\Song;
use App\Services\ActivityService;
use App\Services\FeedItemService;

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

            // Create feed item for discovery feed
            FeedItemService::create([
                'type'               => 'song_release',
                'module'             => 'music',
                'title'              => $song->artist->stage_name . ' released a new track: ' . $song->title,
                'body'               => $song->description,
                'actor_id'           => $song->artist->user->id,
                'actor_type'         => 'artist',
                'actor_name'         => $song->artist->stage_name,
                'actor_avatar_url'   => $song->artist->avatar_url,
                'actor_verified'     => (bool) $song->artist->is_verified,
                'subject_type'       => Song::class,
                'subject_id'         => $song->id,
                'media_type'         => 'song',
                'media_url'          => $song->artwork_url,
                'media_thumbnail_url'=> $song->artwork_url,
                'media_duration_seconds' => $song->duration_seconds,
                'tags'               => array_filter([$song->primaryGenre?->name]),
                'actions'            => [
                    ['type' => 'play', 'label' => 'Play', 'url' => "/songs/{$song->slug}"],
                    ['type' => 'view', 'label' => 'View', 'url' => "/songs/{$song->slug}"],
                ],
                'extras'             => [
                    'song_id'     => $song->id,
                    'artist_name' => $song->artist->stage_name,
                    'is_explicit' => $song->is_explicit,
                ],
            ]);
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

                FeedItemService::create([
                    'type'          => 'song_release',
                    'module'        => 'music',
                    'title'         => $song->artist->stage_name . ' distributed "' . $song->title . '" to streaming platforms',
                    'actor_id'      => $song->artist->user->id,
                    'actor_type'    => 'artist',
                    'actor_name'    => $song->artist->stage_name,
                    'actor_avatar_url' => $song->artist->avatar_url,
                    'actor_verified'=> (bool) $song->artist->is_verified,
                    'subject_type'  => Song::class,
                    'subject_id'    => $song->id,
                    'media_type'    => 'song',
                    'media_url'     => $song->artwork_url,
                    'extras'        => ['platforms' => $song->distribution_platforms ?? []],
                ]);
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

                FeedItemService::create([
                    'type'           => 'song_release',
                    'module'         => 'music',
                    'title'          => $song->artist->stage_name . '\'s "' . $song->title . '" is now featured!',
                    'actor_id'       => $song->artist->user->id,
                    'actor_type'     => 'artist',
                    'actor_name'     => $song->artist->stage_name,
                    'actor_avatar_url'=> $song->artist->avatar_url,
                    'actor_verified' => (bool) $song->artist->is_verified,
                    'subject_type'   => Song::class,
                    'subject_id'     => $song->id,
                    'media_type'     => 'song',
                    'media_url'      => $song->artwork_url,
                    'is_prestige'    => true,
                    'has_celebration'=> true,
                    'extras'         => ['featured' => true],
                ]);
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
