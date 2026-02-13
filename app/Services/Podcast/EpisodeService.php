<?php

namespace App\Services\Podcast;

use App\Models\Podcast;
use App\Models\PodcastEpisode;
use App\Events\Podcast\NewEpisodePublished;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class EpisodeService
{
    /**
     * Create a new episode.
     */
    public function create(Podcast $podcast, array $data): PodcastEpisode
    {
        return DB::transaction(function () use ($podcast, $data) {
            $episode = PodcastEpisode::create([
                'uuid' => Str::uuid(),
                'podcast_id' => $podcast->id,
                'title' => $data['title'],
                'slug' => $this->generateUniqueSlug($data['title'], $podcast->id),
                'episode_number' => $data['episode_number'] ?? null,
                'season_number' => $data['season_number'] ?? 1,
                'episode_type' => $data['episode_type'] ?? 'full',
                'description' => $data['description'] ?? null,
                'show_notes' => $data['show_notes'] ?? null,
                'duration' => 0, // Will be updated after processing
                'file_size' => 0, // Will be updated after processing
                'audio_file_path' => 'temp', // Placeholder
                'status' => 'draft',
                'is_premium' => $data['is_premium'] ?? false,
                'explicit' => $data['explicit'] ?? false,
            ]);

            // Handle audio file upload if provided
            if (isset($data['audio_file']) && $data['audio_file'] instanceof UploadedFile) {
                $this->uploadAudioFile($episode, $data['audio_file']);
            }

            // Handle episode artwork if provided
            if (isset($data['artwork']) && $data['artwork'] instanceof UploadedFile) {
                $this->uploadArtwork($episode, $data['artwork']);
            }

            // Schedule for future if specified
            if (isset($data['scheduled_for'])) {
                $episode->update([
                    'status' => 'scheduled',
                    'scheduled_for' => $data['scheduled_for'],
                ]);
            }

            return $episode->fresh();
        });
    }

    /**
     * Update an existing episode.
     */
    public function update(PodcastEpisode $episode, array $data): PodcastEpisode
    {
        return DB::transaction(function () use ($episode, $data) {
            $episode->update([
                'title' => $data['title'] ?? $episode->title,
                'episode_number' => $data['episode_number'] ?? $episode->episode_number,
                'season_number' => $data['season_number'] ?? $episode->season_number,
                'episode_type' => $data['episode_type'] ?? $episode->episode_type,
                'description' => $data['description'] ?? $episode->description,
                'show_notes' => $data['show_notes'] ?? $episode->show_notes,
                'is_premium' => $data['is_premium'] ?? $episode->is_premium,
                'explicit' => $data['explicit'] ?? $episode->explicit,
            ]);

            // Handle audio file replacement
            if (isset($data['audio_file']) && $data['audio_file'] instanceof UploadedFile) {
                $this->uploadAudioFile($episode, $data['audio_file'], true);
            }

            // Handle artwork update
            if (isset($data['artwork']) && $data['artwork'] instanceof UploadedFile) {
                $this->uploadArtwork($episode, $data['artwork']);
            }

            // Update scheduling
            if (isset($data['scheduled_for'])) {
                $episode->update([
                    'status' => 'scheduled',
                    'scheduled_for' => $data['scheduled_for'],
                ]);
            }

            return $episode->fresh();
        });
    }

    /**
     * Publish an episode.
     */
    public function publish(PodcastEpisode $episode): PodcastEpisode
    {
        if ($episode->status === 'published') {
            return $episode;
        }

        $episode->update([
            'status' => 'published',
            'published_at' => now(),
            'scheduled_for' => null,
        ]);

        // Dispatch event for subscriber notifications
        event(new NewEpisodePublished($episode));

        return $episode->fresh();
    }

    /**
     * Delete an episode.
     */
    public function delete(PodcastEpisode $episode): bool
    {
        return DB::transaction(function () use ($episode) {
            // Delete audio file from storage
            if ($episode->audio_file_path && $episode->audio_file_path !== 'temp') {
                Storage::disk(config('podcast.storage.primary_driver'))
                    ->delete($episode->audio_file_path);
            }

            // Delete artwork if exists
            if ($episode->artwork_url) {
                Storage::disk(config('podcast.storage.primary_driver'))
                    ->delete($episode->artwork_url);
            }

            // Soft delete
            return $episode->delete();
        });
    }

    /**
     * Upload and process audio file.
     */
    protected function uploadAudioFile(PodcastEpisode $episode, UploadedFile $file, bool $replace = false): void
    {
        // Delete old file if replacing
        if ($replace && $episode->audio_file_path && $episode->audio_file_path !== 'temp') {
            Storage::disk(config('podcast.storage.primary_driver'))
                ->delete($episode->audio_file_path);
        }

        // Store audio file
        $path = Storage::disk(config('podcast.storage.primary_driver'))
            ->putFile("podcasts/{$episode->podcast->uuid}/episodes/{$episode->uuid}", $file);

        // Extract metadata (duration, bitrate, sample rate)
        $metadata = $this->extractAudioMetadata($file);

        $episode->update([
            'audio_file_path' => $path,
            'duration' => $metadata['duration'],
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'bitrate' => $metadata['bitrate'] ?? 128,
            'sample_rate' => $metadata['sample_rate'] ?? 44100,
        ]);

        // TODO: Queue transcoding jobs for different quality levels
        // ProcessEpisodeUploadJob::dispatch($episode);
    }

    /**
     * Upload episode artwork.
     */
    protected function uploadArtwork(PodcastEpisode $episode, UploadedFile $image): void
    {
        // Delete old artwork if exists
        if ($episode->artwork_url) {
            Storage::disk(config('podcast.storage.primary_driver'))
                ->delete($episode->artwork_url);
        }

        // Store new artwork
        $path = Storage::disk(config('podcast.storage.primary_driver'))
            ->putFile("podcasts/{$episode->podcast->uuid}/episodes/{$episode->uuid}/artwork", $image);

        $episode->update(['artwork_url' => $path]);
    }

    /**
     * Extract audio metadata from file.
     */
    protected function extractAudioMetadata(UploadedFile $file): array
    {
        // Placeholder implementation
        // TODO: Use getID3 or FFmpeg to extract real metadata
        
        return [
            'duration' => 1800, // 30 minutes placeholder
            'bitrate' => 128,
            'sample_rate' => 44100,
        ];
    }

    /**
     * Generate a unique slug for the episode.
     */
    protected function generateUniqueSlug(string $title, int $podcastId, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $podcastId, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if a slug already exists for this podcast.
     */
    protected function slugExists(string $slug, int $podcastId, ?int $excludeId = null): bool
    {
        $query = PodcastEpisode::where('podcast_id', $podcastId)->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Publish scheduled episodes that are due.
     */
    public function publishScheduledEpisodes(): int
    {
        $episodes = PodcastEpisode::scheduled()
            ->where('scheduled_for', '<=', now())
            ->get();

        $count = 0;
        foreach ($episodes as $episode) {
            $this->publish($episode);
            $count++;
        }

        return $count;
    }
}
