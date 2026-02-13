<?php

namespace App\Repositories\Contracts;

use App\Models\Song;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Song Repository Interface
 * 
 * Defines contract for song data access operations
 */
interface SongRepositoryInterface
{
    /**
     * Find song by ID with relationships
     */
    public function find(int $id, array $relations = []): ?Song;

    /**
     * Get all songs with filtering and pagination
     */
    public function all(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Find trending songs based on recent plays
     */
    public function findTrendingSongs(int $days = 7, int $limit = 20): Collection;

    /**
     * Find songs by genre with analytics
     */
    public function findByGenreWithAnalytics(int $genreId, int $perPage = 20): LengthAwarePaginator;

    /**
     * Find new releases
     */
    public function findNewReleases(int $days = 30, int $limit = 20): Collection;

    /**
     * Find songs by artist
     */
    public function findByArtist(int $artistId, array $filters = []): Collection;

    /**
     * Find songs by album
     */
    public function findByAlbum(int $albumId): Collection;

    /**
     * Search songs by query
     */
    public function search(string $query, int $perPage = 20): LengthAwarePaginator;

    /**
     * Find featured songs
     */
    public function findFeatured(int $limit = 10): Collection;

    /**
     * Find songs by mood
     */
    public function findByMood(string $mood, int $limit = 20): Collection;

    /**
     * Find songs by language
     */
    public function findByLanguage(string $language, int $perPage = 20): LengthAwarePaginator;

    /**
     * Find similar songs based on song attributes
     */
    public function findSimilar(Song $song, int $limit = 10): Collection;

    /**
     * Get user's liked songs
     */
    public function findLikedByUser(int $userId, int $perPage = 20): LengthAwarePaginator;

    /**
     * Get user's downloaded songs
     */
    public function findDownloadedByUser(int $userId, int $perPage = 20): LengthAwarePaginator;

    /**
     * Get songs pending moderation
     */
    public function findPendingModeration(int $perPage = 20): LengthAwarePaginator;

    /**
     * Get most played songs in time period
     */
    public function findMostPlayed(int $days = 7, int $limit = 20): Collection;

    /**
     * Get songs by distribution status
     */
    public function findByDistributionStatus(string $status, int $perPage = 20): LengthAwarePaginator;

    /**
     * Create new song
     */
    public function create(array $data): Song;

    /**
     * Update song
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete song (soft delete)
     */
    public function delete(int $id): bool;

    /**
     * Restore deleted song
     */
    public function restore(int $id): bool;

    /**
     * Get song statistics
     */
    public function getStatistics(int $songId): array;

    /**
     * Increment play count
     */
    public function incrementPlayCount(int $songId): void;

    /**
     * Increment download count
     */
    public function incrementDownloadCount(int $songId): void;

    /**
     * Update cached counts (plays, likes, downloads, comments)
     */
    public function updateCachedCounts(int $songId): void;
}
