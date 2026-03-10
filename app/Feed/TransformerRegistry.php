<?php

namespace App\Feed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Registry of per-entity transformers.
 *
 * Each subject_type (Song, Album, Event, etc.) can register a callable
 * that enriches a FeedItem's extras/media/actions at render time.
 */
class TransformerRegistry
{
    /** @var array<string, callable> */
    protected array $transformers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Register a transformer for a given model class.
     */
    public function register(string $modelClass, callable $transformer): void
    {
        $this->transformers[$modelClass] = $transformer;
    }

    /**
     * Transform a subject model into supplementary feed data.
     *
     * Returns array with optional keys: media, actions, extras, tags.
     */
    public function transform(?string $subjectType, ?int $subjectId): array
    {
        if (! $subjectType || ! $subjectId) {
            return [];
        }

        if (! isset($this->transformers[$subjectType])) {
            return [];
        }

        try {
            $model = $subjectType::find($subjectId);
            if (! $model) {
                return [];
            }

            return call_user_func($this->transformers[$subjectType], $model);
        } catch (\Exception $e) {
            Log::warning("TransformerRegistry: failed for {$subjectType}#{$subjectId}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if a transformer exists for a model class.
     */
    public function has(string $modelClass): bool
    {
        return isset($this->transformers[$modelClass]);
    }

    /**
     * Register built-in transformers for core models.
     */
    protected function registerDefaults(): void
    {
        // Song transformer
        $this->register(\App\Models\Song::class, function (\App\Models\Song $song) {
            return [
                'media' => [
                    'type' => 'song',
                    'url' => $song->artwork_url ?? $song->cover_url,
                    'thumbnail_url' => $song->artwork_url ?? $song->cover_url,
                    'duration_seconds' => $song->duration_seconds,
                    'audio_url' => $song->audio_url,
                ],
                'actions' => [
                    ['type' => 'play', 'label' => 'Play', 'url' => "/songs/{$song->slug}"],
                    ['type' => 'view', 'label' => 'View', 'url' => "/songs/{$song->slug}"],
                ],
                'extras' => [
                    'song_id' => $song->id,
                    'artist_name' => $song->artist?->stage_name,
                    'is_explicit' => $song->is_explicit,
                    'play_count' => $song->play_count,
                ],
                'tags' => array_filter([
                    $song->primaryGenre?->name,
                ]),
            ];
        });

        // Album transformer
        $this->register(\App\Models\Album::class, function (\App\Models\Album $album) {
            return [
                'media' => [
                    'type' => 'album',
                    'url' => $album->artwork_url ?? $album->cover_url,
                    'thumbnail_url' => $album->artwork_url ?? $album->cover_url,
                ],
                'actions' => [
                    ['type' => 'view', 'label' => 'View Album', 'url' => "/albums/{$album->slug}"],
                ],
                'extras' => [
                    'album_id' => $album->id,
                    'track_count' => $album->songs_count ?? $album->songs()->count(),
                    'artist_name' => $album->artist?->stage_name,
                ],
            ];
        });

        // Event transformer
        $this->register(\App\Models\Event::class, function (\App\Models\Event $event) {
            return [
                'media' => [
                    'type' => 'image',
                    'url' => $event->banner_url ?? $event->image_url,
                    'thumbnail_url' => $event->banner_url ?? $event->image_url,
                ],
                'actions' => [
                    ['type' => 'view', 'label' => 'View Event', 'url' => "/events/{$event->slug}"],
                    ['type' => 'register', 'label' => 'Interested', 'url' => "/events/{$event->slug}"],
                ],
                'extras' => [
                    'event_id' => $event->id,
                    'venue' => $event->venue,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ticket_price' => $event->ticket_price,
                ],
            ];
        });
    }

    /**
     * Backward compat: any undefined method returns null.
     */
    public function __call($method, $parameters)
    {
        return null;
    }
}
