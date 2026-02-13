<?php

namespace App\Services;

use App\Models\Song;
use App\Models\Like;
use App\Models\PlayHistory;
use App\Models\Download;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

/**
 * Service class for handling song-related business logic
 *
 * This service encapsulates all song management operations including:
 * - Song retrieval with filtering and sorting
 * - Play tracking and analytics
 * - Download management
 * - Like/Unlike functionality
 * - Song upload and processing
 * - Content moderation
 */
class SongService
{
    protected MusicStorageService $storageService;

    public function __construct(MusicStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Get songs with advanced filtering and sorting
     */
    public function getSongs(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Song::with(['artist', 'album', 'primaryGenre'])
            ->where('status', 'published');

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $this->applySorting($query, $sortBy, $sortOrder);

        $songs = $query->paginate($perPage);

        // Add user-specific data if user is authenticated
        if (auth()->check()) {
            $this->addUserSpecificData($songs->getCollection(), auth()->user());
        }

        return $songs;
    }

    /**
     * Get a single song with detailed information
     */
    public function getSong(int $songId, ?User $user = null): Song
    {
        $song = Song::with(['artist', 'album', 'genre', 'comments.user', 'likes.user'])
            ->findOrFail($songId);

        if ($user) {
            $this->addUserSpecificData(collect([$song]), $user);
        }

        return $song;
    }

    /**
     * Get trending songs based on recent plays
     */
    public function getTrendingSongs(int $days = 7, int $limit = 20): Collection
    {
        return Song::with(['artist', 'album'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->whereHas('playHistory', function($query) use ($days) {
                $query->where('played_at', '>=', now()->subDays($days));
            })
            ->withCount(['playHistory as recent_plays' => function($query) use ($days) {
                $query->where('played_at', '>=', now()->subDays($days));
            }])
            ->orderBy('recent_plays', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get new releases
     */
    public function getNewReleases(int $days = 30, int $limit = 20): Collection
    {
        return Song::with(['artist', 'album'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get songs by genre
     */
    public function getSongsByGenre(string $genreSlug, int $perPage = 20): LengthAwarePaginator
    {
        return Song::with(['artist', 'album', 'genre'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->whereHas('genre', function($query) use ($genreSlug) {
                $query->where('slug', $genreSlug);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Search songs
     */
    public function searchSongs(string $query, int $perPage = 20): LengthAwarePaginator
    {
        return Song::with(['artist', 'album'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->where(function($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhereHas('artist', function($artistQuery) use ($query) {
                      $artistQuery->where('stage_name', 'LIKE', "%{$query}%");
                  })
                  ->orWhereHas('album', function($albumQuery) use ($query) {
                      $albumQuery->where('title', 'LIKE', "%{$query}%");
                  });
            })
            ->orderBy('play_count', 'desc')
            ->paginate($perPage);
    }

    /**
     * Record a song play
     */
    public function recordPlay(Song $song, User $user, array $playData = []): PlayHistory
    {
        // Check if user can play this song
        if (!$this->canUserPlaySong($song, $user)) {
            throw new Exception('Premium subscription required to play this song');
        }

        // Record play history
        $playHistory = PlayHistory::create([
            'user_id' => $user->id,
            'song_id' => $song->id,
            'artist_id' => $song->artist_id,
            'album_id' => $song->album_id,
            'played_at' => now(),
            'duration_played_seconds' => $playData['duration_played_seconds'] ?? 0,
            'completed' => $playData['completed'] ?? false,
            'skipped' => ($playData['duration_played_seconds'] ?? 0) < 30,
            'device_type' => $playData['device_type'] ?? 'web',
            'quality' => $playData['quality'] ?? '128',
        ]);

        // Update song play count if completed
        if ($playData['completed'] ?? false) {
            $song->increment('play_count');

            // Create activity
            $this->createUserActivity($user, 'played_song', $song);
        }

        return $playHistory;
    }

    /**
     * Handle song download
     */
    public function downloadSong(Song $song, User $user): array
    {
        // Check if user can download
        if (!$this->canUserDownload($user)) {
            throw new Exception('Download limit reached. Upgrade to premium for unlimited downloads.');
        }

        // Check if song is available for download
        if (!$song->isAvailableForDownload()) {
            throw new Exception('This song is not available for download');
        }

        // Check for existing download
        $existingDownload = Download::where('user_id', $user->id)
            ->where('song_id', $song->id)
            ->first();

        if ($existingDownload) {
            return [
                'download_url' => $song->getDownloadUrlAttribute(),
                'expires_at' => $existingDownload->expires_at,
                'message' => 'Song already downloaded'
            ];
        }

        // Create download record
        $download = Download::create([
            'user_id' => $user->id,
            'song_id' => $song->id,
            'quality' => '320kbps',
            'file_size_bytes' => $song->file_size_bytes,
            'downloaded_at' => now(),
            'expires_at' => $song->is_free ? null : now()->addDays(30),
        ]);

        // Increment download count
        $song->increment('download_count');

        // Create activity
        $this->createUserActivity($user, 'downloaded_song', $song);

        return [
            'download_url' => $song->getDownloadUrlAttribute(),
            'expires_at' => $download->expires_at,
            'message' => 'Download initiated successfully'
        ];
    }

    /**
     * Toggle like/unlike for a song
     */
    public function toggleLike(Song $song, User $user): array
    {
        $isLiked = Like::toggle($user, $song);

        return [
            'is_liked' => $isLiked,
            'like_count' => $song->fresh()->like_count,
            'message' => $isLiked ? 'Song liked' : 'Song unliked'
        ];
    }

    /**
     * Upload and process a new song
     */
    public function uploadSong(array $songData, User $user): Song
    {
        DB::beginTransaction();

        try {
            // Validate artist permissions
            if (!$this->canUserUploadSong($user)) {
                throw new Exception('You do not have permission to upload songs');
            }

            // Get or create artist profile for user
            if (!$user->artist) {
                throw new Exception('User must have an artist profile to upload songs');
            }

            // Process file upload
            $fileData = $this->storageService->uploadSong($songData['file'], $user);

            // Create song record
            $song = Song::create([
                'title' => $songData['title'],
                'description' => $songData['description'] ?? null,
                'user_id' => $user->id,
                'artist_id' => $user->artist->id,
                'album_id' => $songData['album_id'] ?? null,
                'primary_genre_id' => $songData['genre_id'] ?? null,
                'duration' => $fileData['duration'],
                'duration_seconds' => $fileData['duration'],
                'audio_file_original' => $fileData['file_path'],
                'file_size_bytes' => $fileData['file_size'] ?? $fileData['file_size_bytes'] ?? 0,
                'file_format' => $fileData['file_format'],
                'is_free' => $songData['is_free'] ?? false,
                'is_explicit' => $songData['is_explicit'] ?? false,
                'primary_language' => $songData['language'] ?? 'en',
                'mood_tags' => $songData['moods'] ?? [],
                'status' => 'pending_review',
            ]);

            // Handle cover art if provided
            if (isset($songData['cover_art'])) {
                $coverData = $this->storageService->uploadCoverArt($songData['cover_art'], $song);
                $song->update(['artwork' => $coverData['file_path']]);
            }

            // Attach moods if provided as IDs
            if (isset($songData['mood_ids']) && is_array($songData['mood_ids'])) {
                $song->moods()->attach($songData['mood_ids']);
            }

            DB::commit();

            return $song;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update song metadata
     */
    public function updateSong(Song $song, array $updateData, User $user): Song
    {
        // Check permissions
        if (!$this->canUserEditSong($song, $user)) {
            throw new Exception('You do not have permission to edit this song');
        }

        $allowedFields = [
            'title', 'description', 'primary_genre_id', 'album_id', 'is_free',
            'is_explicit', 'primary_language', 'mood_tags'
        ];

        $updateData = array_intersect_key($updateData, array_flip($allowedFields));

        $song->update($updateData);

        return $song->fresh();
    }

    /**
     * Delete a song
     */
    public function deleteSong(Song $song, User $user): bool
    {
        // Check permissions
        if (!$this->canUserDeleteSong($song, $user)) {
            throw new Exception('You do not have permission to delete this song');
        }

        DB::beginTransaction();

        try {
            // Delete associated files
            $this->storageService->deleteSongFiles($song);

            // Mark song as removed (soft delete alternative)
            $song->update(['status' => 'removed']);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Moderate song content
     */
    public function moderateSong(Song $song, string $action, User $moderator, ?string $reason = null): Song
    {
        // Check moderator permissions
        if (!$moderator->hasRole('moderator') && 
            !$moderator->hasRole('admin') && 
            !$moderator->hasRole('super_admin') && 
            !$moderator->hasPermission('music.moderate')) {
            throw new Exception('You do not have permission to moderate content');
        }

        $validActions = ['approve', 'reject', 'flag', 'unflag'];

        if (!in_array($action, $validActions)) {
            throw new Exception('Invalid moderation action');
        }

        $statusMap = [
            'approve' => 'published',
            'reject' => 'rejected',
            'flag' => 'flagged',
            'unflag' => 'published'
        ];

        $song->update([
            'status' => $statusMap[$action],
            'moderated_at' => now(),
            'moderated_by' => $moderator->id,
            'moderation_reason' => $reason,
        ]);

        // Notify artist of moderation decision
        $this->notifyArtistOfModeration($song, $action, $reason);

        return $song->fresh();
    }

    /**
     * Get song analytics
     */
    public function getSongAnalytics(Song $song, int $days = 30): array
    {
        $startDate = now()->subDays($days);

        return [
            'total_plays' => $song->play_count,
            'recent_plays' => PlayHistory::where('song_id', $song->id)
                ->where('played_at', '>=', $startDate)
                ->count(),
            'total_likes' => $song->like_count,
            'total_downloads' => $song->download_count,
            'completion_rate' => $this->calculateCompletionRate($song, $days),
            'daily_plays' => $this->getDailyPlays($song, $days),
            'listener_demographics' => $this->getListenerDemographics($song, $days),
        ];
    }

    /**
     * Apply filters to the song query
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['genre'])) {
            $query->whereHas('primaryGenre', function($q) use ($filters) {
                $q->where('slug', $filters['genre']);
            });
        }

        if (isset($filters['mood'])) {
            $query->whereJsonContains('moods', $filters['mood']);
        }

        if (isset($filters['is_free'])) {
            $query->where('is_free', (bool) $filters['is_free']);
        }

        if (isset($filters['language'])) {
            $query->where('primary_language', $filters['language']);
        }

        if (isset($filters['artist_id'])) {
            $query->where('artist_id', $filters['artist_id']);
        }

        if (isset($filters['album_id'])) {
            $query->where('album_id', $filters['album_id']);
        }
    }

    /**
     * Apply sorting to the song query
     */
    protected function applySorting($query, string $sortBy, string $sortOrder): void
    {
        switch ($sortBy) {
            case 'popularity':
                $query->orderBy('play_count', $sortOrder);
                break;
            case 'likes':
                $query->orderBy('like_count', $sortOrder);
                break;
            case 'downloads':
                $query->orderBy('download_count', $sortOrder);
                break;
            default:
                $query->orderBy($sortBy, $sortOrder);
        }
    }

    /**
     * Add user-specific data to songs
     */
    protected function addUserSpecificData(Collection $songs, User $user): void
    {
        $songIds = $songs->pluck('id');

        // Get user's likes
        $likedSongs = Like::where('user_id', $user->id)
            ->where('likeable_type', Song::class)
            ->whereIn('likeable_id', $songIds)
            ->pluck('likeable_id')
            ->flip();

        // Get user's downloads
        $downloadedSongs = Download::where('user_id', $user->id)
            ->whereIn('song_id', $songIds)
            ->pluck('song_id')
            ->flip();

        $songs->each(function ($song) use ($likedSongs, $downloadedSongs) {
            $song->is_liked = $likedSongs->has($song->id);
            $song->is_downloaded = $downloadedSongs->has($song->id);
        });
    }

    /**
     * Check if user can play the song
     */
    protected function canUserPlaySong(Song $song, User $user): bool
    {
        if ($song->is_free) {
            return true;
        }

        return $user->hasActiveSubscription() || $user->canPlayPremiumContent();
    }

    /**
     * Check if user can download songs
     */
    protected function canUserDownload(User $user): bool
    {
        return $user->canDownload();
    }

    /**
     * Check if user can upload songs
     */
    protected function canUserUploadSong(User $user): bool
    {
        // Artists can upload songs
        if ($user->hasRole('artist') || $user->hasRole('admin') || $user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasPermission('music.upload');
    }

    /**
     * Check if user can edit the song
     */
    protected function canUserEditSong(Song $song, User $user): bool
    {
        return $song->user_id === $user->id || 
               $user->hasRole('admin') || 
               $user->hasRole('super_admin') || 
               $user->hasPermission('music.edit_any');
    }

    /**
     * Check if user can delete the song
     */
    protected function canUserDeleteSong(Song $song, User $user): bool
    {
        return $song->user_id === $user->id || 
               $user->hasRole('admin') || 
               $user->hasRole('super_admin') || 
               $user->hasPermission('music.delete_any');
    }

    /**
     * Create user activity record
     */
    protected function createUserActivity(User $user, string $type, Song $song): void
    {
        $user->activities()->create([
            'activity_type' => $type,
            'subject_type' => Song::class,
            'subject_id' => $song->id,
            'metadata' => [
                'song_title' => $song->title,
                'artist_name' => $song->artist->stage_name ?? 'Unknown',
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Calculate completion rate for a song
     */
    protected function calculateCompletionRate(Song $song, int $days): float
    {
        $totalPlays = PlayHistory::where('song_id', $song->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->count();

        if ($totalPlays === 0) {
            return 0;
        }

        $completedPlays = PlayHistory::where('song_id', $song->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->where('was_completed', true)
            ->count();

        return round(($completedPlays / $totalPlays) * 100, 2);
    }

    /**
     * Get daily plays for a song
     */
    protected function getDailyPlays(Song $song, int $days): array
    {
        return PlayHistory::where('song_id', $song->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(played_at) as date, COUNT(*) as plays')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('plays', 'date')
            ->toArray();
    }

    /**
     * Get listener demographics for a song
     */
    protected function getListenerDemographics(Song $song, int $days): array
    {
        return PlayHistory::where('song_id', $song->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->join('users', 'play_histories.user_id', '=', 'users.id')
            ->selectRaw('users.country, COUNT(*) as plays')
            ->groupBy('users.country')
            ->orderBy('plays', 'desc')
            ->pluck('plays', 'country')
            ->toArray();
    }

    /**
     * Notify artist of moderation decision
     */
    protected function notifyArtistOfModeration(Song $song, string $action, ?string $reason): void
    {
        $song->artist->notifications()->create([
            'notification_type' => 'song_moderated',  // Fixed: use notification_type instead of type
            'title' => 'Song Moderation Update',
            'message' => "Your song '{$song->title}' has been {$action}.",
            'metadata' => [  // Fixed: use metadata instead of data
                'song_id' => $song->id,
                'action' => $action,
                'reason' => $reason,
            ],
        ]);
    }
}