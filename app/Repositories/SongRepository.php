<?php

namespace App\Repositories;

use App\Models\Song;
use App\Models\PlayHistory;
use App\Models\Like;
use App\Models\Download;
use App\Repositories\Contracts\SongRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Song Repository
 * 
 * Implements data access layer for Song model
 */
class SongRepository implements SongRepositoryInterface
{
    protected Song $model;

    public function __construct(Song $model)
    {
        $this->model = $model;
    }

    /**
     * Find song by ID with relationships
     */
    public function find(int $id, array $relations = []): ?Song
    {
        $query = $this->model->with($relations);

        return Cache::remember(
            "song:{$id}:" . md5(json_encode($relations)),
            3600, // 1 hour
            fn() => $query->find($id)
        );
    }

    /**
     * Get all songs with filtering and pagination
     */
    public function all(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->query()
            ->with(['artist', 'album', 'genres'])
            ->where('status', 'published')
            ->where('visibility', 'public');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Find trending songs based on recent plays
     */
    public function findTrendingSongs(int $days = 7, int $limit = 20): Collection
    {
        return Cache::remember("trending:songs:{$days}:{$limit}", 900, function() use ($days, $limit) {
            return $this->model
                ->with(['artist', 'album'])
                ->where('status', 'published')
                ->whereHas('playHistory', function($query) use ($days) {
                    $query->where('played_at', '>=', now()->subDays($days));
                })
                ->withCount(['playHistory as recent_plays' => function($query) use ($days) {
                    $query->where('played_at', '>=', now()->subDays($days));
                }])
                ->orderBy('recent_plays', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Find songs by genre with analytics
     */
    public function findByGenreWithAnalytics(int $genreId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->where('primary_genre_id', $genreId)
            ->withCount(['playHistory', 'likes', 'downloads'])
            ->orderBy('play_count', 'desc')
            ->paginate($perPage);
    }

    /**
     * Find new releases
     */
    public function findNewReleases(int $days = 30, int $limit = 20): Collection
    {
        return Cache::remember("new:releases:{$days}:{$limit}", 1800, function() use ($days, $limit) {
            return $this->model
                ->with(['artist', 'album'])
                ->where('status', 'published')
                ->where('release_date', '>=', now()->subDays($days))
                ->orderBy('release_date', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Find songs by artist
     */
    public function findByArtist(int $artistId, array $filters = []): Collection
    {
        $query = $this->model
            ->where('artist_id', $artistId)
            ->with(['album', 'genres']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'published');
        }

        return $query->orderBy('release_date', 'desc')->get();
    }

    /**
     * Find songs by album
     */
    public function findByAlbum(int $albumId): Collection
    {
        return $this->model
            ->where('album_id', $albumId)
            ->where('status', 'published')
            ->with(['artist'])
            ->orderBy('track_number')
            ->orderBy('disc_number')
            ->get();
    }

    /**
     * Search songs by query
     */
    public function search(string $query, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->where(function($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhere('lyrics', 'LIKE', "%{$query}%")
                  ->orWhereHas('artist', function($artistQuery) use ($query) {
                      $artistQuery->where('name', 'LIKE', "%{$query}%")
                                  ->orWhere('stage_name', 'LIKE', "%{$query}%");
                  })
                  ->orWhereHas('album', function($albumQuery) use ($query) {
                      $albumQuery->where('title', 'LIKE', "%{$query}%");
                  });
            })
            ->orderBy('play_count', 'desc')
            ->paginate($perPage);
    }

    /**
     * Find featured songs
     */
    public function findFeatured(int $limit = 10): Collection
    {
        return Cache::remember("featured:songs:{$limit}", 3600, function() use ($limit) {
            return $this->model
                ->with(['artist', 'album'])
                ->where('status', 'published')
                ->where('is_featured', true)
                ->orderBy('featured_at', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Find songs by mood
     */
    public function findByMood(string $mood, int $limit = 20): Collection
    {
        return $this->model
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->whereJsonContains('mood_tags', $mood)
            ->orderBy('play_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find songs by language
     */
    public function findByLanguage(string $language, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->where(function($q) use ($language) {
                $q->where('primary_language', $language)
                  ->orWhereJsonContains('languages_sung', $language);
            })
            ->orderBy('release_date', 'desc')
            ->paginate($perPage);
    }

    /**
     * Find similar songs based on song attributes
     */
    public function findSimilar(Song $song, int $limit = 10): Collection
    {
        return $this->model
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->where('id', '!=', $song->id)
            ->where(function($query) use ($song) {
                $query->where('primary_genre_id', $song->primary_genre_id)
                      ->orWhere('artist_id', $song->artist_id)
                      ->orWhere(function($q) use ($song) {
                          if ($song->mood_tags) {
                              foreach ($song->mood_tags as $mood) {
                                  $q->orWhereJsonContains('mood_tags', $mood);
                              }
                          }
                      });
            })
            ->orderByRaw('
                (CASE 
                    WHEN artist_id = ? THEN 3
                    WHEN primary_genre_id = ? THEN 2
                    ELSE 1
                END) DESC',
                [$song->artist_id, $song->primary_genre_id]
            )
            ->orderBy('play_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user's liked songs
     */
    public function findLikedByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'album'])
            ->whereHas('likes', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderByDesc(
                Like::select('created_at')
                    ->whereColumn('likeable_id', 'songs.id')
                    ->where('likeable_type', Song::class)
                    ->where('user_id', $userId)
                    ->limit(1)
            )
            ->paginate($perPage);
    }

    /**
     * Get user's downloaded songs
     */
    public function findDownloadedByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'album'])
            ->whereHas('downloads', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderByDesc(
                Download::select('downloaded_at')
                    ->whereColumn('downloadable_id', 'songs.id')
                    ->where('downloadable_type', Song::class)
                    ->where('user_id', $userId)
                    ->limit(1)
            )
            ->paginate($perPage);
    }

    /**
     * Get songs pending moderation
     */
    public function findPendingModeration(int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist', 'user'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * Get most played songs in time period
     */
    public function findMostPlayed(int $days = 7, int $limit = 20): Collection
    {
        return Cache::remember("most:played:{$days}:{$limit}", 900, function() use ($days, $limit) {
            return $this->model
                ->with(['artist', 'album'])
                ->where('status', 'published')
                ->where('created_at', '>=', now()->subDays($days))
                ->orderBy('play_count', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get songs by distribution status
     */
    public function findByDistributionStatus(string $status, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(['artist'])
            ->where('distribution_status', $status)
            ->orderBy('distribution_requested_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Create new song
     */
    public function create(array $data): Song
    {
        return $this->model->create($data);
    }

    /**
     * Update song
     */
    public function update(int $id, array $data): bool
    {
        $song = $this->model->find($id);
        
        if (!$song) {
            return false;
        }

        $updated = $song->update($data);

        // Clear cache
        Cache::forget("song:{$id}");

        return $updated;
    }

    /**
     * Delete song (soft delete)
     */
    public function delete(int $id): bool
    {
        $song = $this->model->find($id);
        
        if (!$song) {
            return false;
        }

        // Clear cache
        Cache::forget("song:{$id}");

        return $song->delete();
    }

    /**
     * Restore deleted song
     */
    public function restore(int $id): bool
    {
        $song = $this->model->withTrashed()->find($id);
        
        if (!$song) {
            return false;
        }

        return $song->restore();
    }

    /**
     * Get song statistics
     */
    public function getStatistics(int $songId): array
    {
        return Cache::remember("song:stats:{$songId}", 1800, function() use ($songId) {
            $song = $this->model->find($songId);

            if (!$song) {
                return [];
            }

            return [
                'total_plays' => $song->play_count,
                'total_likes' => $song->like_count,
                'total_downloads' => $song->download_count,
                'total_comments' => $song->comment_count,
                'unique_listeners' => $song->unique_listeners_count ?? PlayHistory::where('song_id', $songId)
                    ->distinct('user_id')
                    ->whereNotNull('user_id')
                    ->count('user_id'), // Calculate if not cached
                'recent_plays_7d' => PlayHistory::where('song_id', $songId)
                    ->where('played_at', '>=', now()->subDays(7))
                    ->count(),
                'recent_plays_30d' => PlayHistory::where('song_id', $songId)
                    ->where('played_at', '>=', now()->subDays(30))
                    ->count(),
                'completion_rate' => $this->calculateCompletionRate($songId),
            ];
        });
    }

    /**
     * Increment play count
     */
    public function incrementPlayCount(int $songId): void
    {
        $this->model->where('id', $songId)->increment('play_count');
        Cache::forget("song:stats:{$songId}");
    }

    /**
     * Increment download count
     */
    public function incrementDownloadCount(int $songId): void
    {
        $this->model->where('id', $songId)->increment('download_count');
        Cache::forget("song:stats:{$songId}");
    }

    /**
     * Update cached counts
     */
    public function updateCachedCounts(int $songId): void
    {
        $song = $this->model->find($songId);

        if (!$song) {
            return;
        }

        $song->update([
            'play_count' => PlayHistory::where('song_id', $songId)->count(),
            'like_count' => Like::where('likeable_type', Song::class)
                ->where('likeable_id', $songId)->count(),
            'download_count' => Download::where('downloadable_type', Song::class)
                ->where('downloadable_id', $songId)->count(),
        ]);

        Cache::forget("song:stats:{$songId}");
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['genre_id'])) {
            $query->where('primary_genre_id', $filters['genre_id']);
        }

        if (isset($filters['artist_id'])) {
            $query->where('artist_id', $filters['artist_id']);
        }

        if (isset($filters['language'])) {
            $query->where('primary_language', $filters['language']);
        }

        if (isset($filters['is_free'])) {
            $query->where('is_free', $filters['is_free']);
        }

        if (isset($filters['is_explicit'])) {
            $query->where('is_explicit', $filters['is_explicit']);
        }

        if (isset($filters['sort_by'])) {
            $sortOrder = $filters['sort_order'] ?? 'desc';
            $query->orderBy($filters['sort_by'], $sortOrder);
        } else {
            $query->orderBy('release_date', 'desc');
        }
    }

    /**
     * Calculate completion rate
     */
    protected function calculateCompletionRate(int $songId): float
    {
        $totalPlays = PlayHistory::where('song_id', $songId)->count();

        if ($totalPlays === 0) {
            return 0;
        }

        $completedPlays = PlayHistory::where('song_id', $songId)
            ->where('was_completed', true)
            ->count();

        return round(($completedPlays / $totalPlays) * 100, 2);
    }
}
