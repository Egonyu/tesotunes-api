<?php

namespace App\Services\Podcast;

use App\Models\Podcast;
use App\Models\User;
use App\Events\Podcast\NewPodcastPublished;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class PodcastService
{
    /**
     * Create a new podcast.
     */
    public function create(array $data, User $user): Podcast
    {
        return DB::transaction(function () use ($data, $user) {
            // Get or create artist for user
            if (isset($data['artist_id'])) {
                $artistId = $data['artist_id'];
            } elseif ($user->artist) {
                $artistId = $user->artist->id;
            } else {
                // Check if artist exists (in case it was just created)
                $artist = \App\Models\Artist::where('user_id', $user->id)->first();
                if (!$artist) {
                    // Create an artist for the user if they don't have one
                    $artist = \App\Models\Artist::create([
                        'user_id' => $user->id,
                        'stage_name' => $user->name,
                        'slug' => \Illuminate\Support\Str::slug($user->name) . '-' . $user->id,
                    ]);
                }
                $artistId = $artist->id;
            }

            // Generate UUID if not provided
            $uuid = $data['uuid'] ?? (string) Str::uuid();
            
            // Generate author name
            $authorName = $data['author_name'] ?? $user->name;
            
            // Generate copyright
            $copyright = $data['copyright'] ?? '© ' . now()->year . ' ' . $authorName;
            
            // Generate RSS feed URL
            $rssFeedUrl = $data['rss_feed_url'] ?? url("/podcasts/rss/{$uuid}");

            $podcast = Podcast::create([
                'artist_id' => $artistId,
                'user_id' => $user->id,
                'podcast_category_id' => $data['podcast_category_id'] ?? $data['category_id'] ?? null,
                'title' => $data['title'],
                'slug' => $this->generateUniqueSlug($data['title']),
                'description' => $data['description'] ?? null,
                'language' => $data['language'] ?? 'en',
                'is_explicit' => $data['is_explicit'] ?? $data['explicit_content'] ?? false,
                'status' => 'draft',
                'uuid' => $uuid,
                'rss_guid' => $data['rss_guid'] ?? $uuid,
                'rss_feed_url' => $rssFeedUrl,
                'author_name' => $authorName,
                'copyright' => $copyright,
                'is_premium' => $data['is_premium'] ?? false,
                'tags' => $data['tags'] ?? null,
            ]);

            // Handle artwork upload
            if (isset($data['cover_image']) && $data['cover_image'] instanceof UploadedFile) {
                $this->uploadArtwork($podcast, $data['cover_image']);
            } elseif (isset($data['artwork'])) {
                $podcast->update(['artwork' => $data['artwork']]);
            }

            // Increment category count
            if ($podcast->category) {
                $podcast->category->incrementPodcastCount();
            }

            return $podcast->fresh();
        });
    }

    /**
     * Update an existing podcast.
     */
    public function update(Podcast $podcast, array $data): Podcast
    {
        return DB::transaction(function () use ($podcast, $data) {
            $oldCategoryId = $podcast->podcast_category_id;

            $podcast->update([
                'title' => $data['title'] ?? $podcast->title,
                'description' => $data['description'] ?? $podcast->description,
                'language' => $data['language'] ?? $podcast->language,
                'is_explicit' => $data['is_explicit'] ?? $data['explicit_content'] ?? $podcast->is_explicit,
                'podcast_category_id' => $data['podcast_category_id'] ?? $data['category_id'] ?? $podcast->podcast_category_id,
            ]);

            // Handle artwork update
            if (isset($data['cover_image']) && $data['cover_image'] instanceof UploadedFile) {
                $this->uploadArtwork($podcast, $data['cover_image']);
            } elseif (isset($data['artwork'])) {
                $podcast->update(['artwork' => $data['artwork']]);
            }

            // Update category counts if changed
            if ($oldCategoryId !== $podcast->podcast_category_id) {
                if ($oldCategory = \App\Models\PodcastCategory::find($oldCategoryId)) {
                    $oldCategory->decrementPodcastCount();
                }
                if ($podcast->category) {
                    $podcast->category->incrementPodcastCount();
                }
            }

            return $podcast->fresh();
        });
    }

    /**
     * Publish a podcast.
     */
    public function publish(Podcast $podcast): Podcast
    {
        if ($podcast->status === 'published') {
            return $podcast;
        }

        $podcast->update([
            'status' => 'published',
        ]);

        // Dispatch event to notify subscribers and trigger related actions
        event(new NewPodcastPublished($podcast));

        return $podcast->fresh();
    }

    /**
     * Archive a podcast.
     */
    public function archive(Podcast $podcast): Podcast
    {
        $podcast->update(['status' => 'archived']);

        return $podcast->fresh();
    }

    /**
     * Delete a podcast and all its episodes.
     */
    public function delete(Podcast $podcast): bool
    {
        return DB::transaction(function () use ($podcast) {
            // Decrement category count
            if ($podcast->category) {
                $podcast->category->decrementPodcastCount();
            }

            // Delete artwork from storage
            if ($podcast->artwork) {
                Storage::disk(config('podcast.storage.primary_driver', 'digitalocean'))
                    ->delete($podcast->artwork);
            }

            // Soft delete
            return $podcast->delete();
        });
    }

    /**
     * Upload and store podcast artwork.
     */
    protected function uploadArtwork(Podcast $podcast, UploadedFile $image): void
    {
        // Delete old artwork if exists
        if ($podcast->artwork) {
            Storage::disk(config('podcast.storage.primary_driver', 'digitalocean'))
                ->delete($podcast->artwork);
        }

        // Store new artwork
        $path = Storage::disk(config('podcast.storage.primary_driver', 'digitalocean'))
            ->putFile("podcasts/{$podcast->id}/artwork", $image);

        $podcast->update(['artwork' => $path]);

        // TODO: Generate thumbnail version
    }

    /**
     * Generate a unique slug for the podcast.
     */
    protected function generateUniqueSlug(string $title, ?int $id = null): string
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $id)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if a slug already exists.
     */
    protected function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Podcast::where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Get trending podcasts.
     */
    public function getTrending(int $limit = 10)
    {
        return Podcast::published()
            ->orderBy('total_listen_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Search podcasts.
     */
    public function search(string $query, ?int $categoryId = null, int $perPage = 20)
    {
        $builder = Podcast::published()
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%");
            });

        if ($categoryId) {
            $builder->where('podcast_category_id', $categoryId);
        }

        return $builder->paginate($perPage);
    }
}
