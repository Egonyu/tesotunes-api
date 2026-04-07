<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Notifications\AdminSongPendingNotification;
use App\Notifications\SongModerationNotification;
use App\Services\Audio\AudioMetadataService;
use App\Services\Music\ISRCService;
use App\Services\NotificationRoutingService;
use App\Traits\HandlesApiErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SongsApiController extends Controller
{
    use HandlesApiErrors;

    private static ?array $songTableColumns = null;

    public function __construct(
        private readonly NotificationRoutingService $notificationRoutingService,
        private readonly AudioMetadataService $audioMetadataService,
        private readonly ISRCService $isrcService
    ) {}

    private function storeUploadedFile(UploadedFile $file, string $directory): string
    {
        return StorageHelper::store(
            $file,
            $directory,
            Str::uuid().'.'.$file->getClientOriginalExtension()
        );
    }

    private function persistableSongAttributes(array $attributes): array
    {
        $columns = self::$songTableColumns ??= array_flip(Schema::getColumnListing((new Song)->getTable()));

        return array_intersect_key($attributes, $columns);
    }

    private function hasSongTableColumn(string $column): bool
    {
        $columns = self::$songTableColumns ??= array_flip(Schema::getColumnListing((new Song)->getTable()));

        return isset($columns[$column]);
    }

    private function parseDurationInput(null|string|int|float $duration): ?int
    {
        if ($duration === null || $duration === '') {
            return null;
        }

        if (is_numeric($duration)) {
            return max(0, (int) round((float) $duration));
        }

        $parts = explode(':', trim((string) $duration));
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    private function assertValidUpload(?UploadedFile $file, string $field): void
    {
        if (! $file) {
            return;
        }

        // On Windows dev, temp files can be cleaned prematurely.
        // Only check isValid() which verifies the upload error code.
        if ($file->isValid()) {
            return;
        }

        Log::warning('SongsApiController invalid upload payload', [
            'field' => $field,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'error' => method_exists($file, 'getError') ? $file->getError() : null,
            'error_message' => method_exists($file, 'getErrorMessage') ? $file->getErrorMessage() : null,
        ]);

        throw ValidationException::withMessages([
            $field => ['Uploaded file is invalid. Please reselect the file and try again.'],
        ]);
    }

    private function notifyArtistForSongStatus(Song $song, string $status, ?string $reason = null): void
    {
        $song->loadMissing('artist.user');

        $recipient = $song->artist?->user;
        if (! $recipient) {
            return;
        }

        $recipient->notify(new SongModerationNotification($song, $status, $reason));
    }

    private function notifyModeratorsSongPending(Song $song): void
    {
        $song->loadMissing('artist.user');
        $artistUser = $song->artist?->user;

        if (! $artistUser) {
            return;
        }

        foreach ($this->notificationRoutingService->moderationRecipients() as $reviewer) {
            $reviewer->notify(new AdminSongPendingNotification($song, $artistUser));
        }
    }

    private function applyAuthorizedForIsrcScope(Builder $query): Builder
    {
        return $query->where(function (Builder $authorizedQuery) {
            $authorizedQuery
                ->whereIn('distribution_status', ['approved', 'distributed'])
                ->orWhere(function (Builder $publishedQuery) {
                    $publishedQuery
                        ->where('status', 'published')
                        ->whereNotNull('approved_at');
                });
        });
    }

    private function applyIsrcReadyScope(Builder $query): Builder
    {
        $query = $this->applyAuthorizedForIsrcScope($query)
            ->whereNull('isrc_code')
            ->whereNotNull('artist_id')
            ->whereNotNull('title')
            ->where('duration_seconds', '>', 0)
            ->where(function (Builder $audioQuery) {
                $audioQuery
                    ->whereNotNull('audio_file_original')
                    ->orWhereNotNull('audio_file_320')
                    ->orWhereNotNull('audio_file_128');
            });

        if ($this->hasSongTableColumn('master_ownership_percentage') && $this->hasSongTableColumn('publishing_ownership_percentage')) {
            $query->whereRaw('COALESCE(master_ownership_percentage, 0) + COALESCE(publishing_ownership_percentage, 0) <= 200');
        }

        return $query;
    }

    private function applyIsrcStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'assigned' => $query->whereNotNull('isrc_code'),
            'ready' => $this->applyIsrcReadyScope($query),
            'blocked' => $this->applyAuthorizedForIsrcScope($query)
                ->whereNull('isrc_code')
                ->where(function (Builder $blockedQuery) {
                    $blockedQuery
                        ->whereNull('artist_id')
                        ->orWhereNull('title')
                        ->orWhere('duration_seconds', '<=', 0)
                        ->orWhere(function (Builder $audioMissingQuery) {
                            $audioMissingQuery
                                ->whereNull('audio_file_original')
                                ->whereNull('audio_file_320')
                                ->whereNull('audio_file_128');
                        });

                    if ($this->hasSongTableColumn('master_ownership_percentage') && $this->hasSongTableColumn('publishing_ownership_percentage')) {
                        $blockedQuery->orWhereRaw('COALESCE(master_ownership_percentage, 0) + COALESCE(publishing_ownership_percentage, 0) > 200');
                    }
                }),
            default => $query,
        };
    }

    private function notifySongStatusTransition(Song $song, ?string $previousStatus, ?string $reason = null): void
    {
        if ($previousStatus === $song->status) {
            return;
        }

        if ($song->status === 'pending') {
            $this->notifyArtistForSongStatus($song, SongModerationNotification::PENDING_REVIEW, $reason);
            $this->notifyModeratorsSongPending($song);
        }

        if ($song->status === 'published') {
            $this->notifyArtistForSongStatus($song, SongModerationNotification::APPROVED, $reason);
        }

        if ($song->status === 'rejected') {
            $this->notifyArtistForSongStatus($song, SongModerationNotification::REJECTED, $reason ?? $song->rejection_reason);
        }
    }

    /**
     * List songs with filtering, searching, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = Song::with(['artist', 'album', 'primaryGenre']);

            // Search
            if ($search = $request->input('search')) {
                $escapedSearch = addcslashes($search, '%_');
                $query->where(function ($q) use ($escapedSearch) {
                    $q->where('title', 'like', "%{$escapedSearch}%")
                        ->orWhere('slug', 'like', "%{$escapedSearch}%")
                        ->orWhereHas('artist', function ($aq) use ($escapedSearch) {
                            $aq->where('stage_name', 'like', "%{$escapedSearch}%");
                        });
                });
            }

            // Filter by status
            if ($status = $request->input('status')) {
                if (in_array($status, ['pending', 'pending_review'], true)) {
                    $query->whereIn('status', ['pending', 'pending_review']);
                } else {
                    $query->where('status', $status);
                }
            }

            if ($isrcStatus = $request->input('isrc_status')) {
                $this->applyIsrcStatusFilter($query, (string) $isrcStatus);
            }

            // Filter by genre
            if ($genre = $request->input('genre')) {
                $query->where('primary_genre_id', $genre);
            }

            // Filter by artist
            if ($artistId = $request->input('artist_id')) {
                $query->where('artist_id', $artistId);
            }

            // Filter by featured
            if ($request->has('featured')) {
                $query->where('is_featured', $request->boolean('featured'));
            }

            // Sorting
            $sort = $request->input('sort', '-created_at');
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $column = ltrim($sort, '-');
            $allowedSorts = ['title', 'created_at', 'updated_at', 'play_count', 'like_count', 'download_count', 'release_date'];
            if (in_array($column, $allowedSorts)) {
                $query->orderBy($column, $direction);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $perPage = min($request->input('per_page', 20), 100);
            $songs = $query->paginate($perPage);

            return SongResource::collection($songs)->response();
        }, 'Failed to fetch songs.');
    }

    /**
     * Get song statistics for admin dashboard.
     */
    public function statistics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $pendingReviewCount = Song::whereIn('status', ['pending', 'pending_review'])->count();
            $isrcAssigned = Song::whereNotNull('isrc_code')->count();
            $isrcReady = $this->applyIsrcReadyScope(Song::query())->count();
            $isrcBlocked = $this->applyIsrcStatusFilter(Song::query(), 'blocked')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => Song::count(),
                    'published' => Song::where('status', 'published')->count(),
                    'draft' => Song::where('status', 'draft')->count(),
                    'pending' => $pendingReviewCount,
                    'pending_review' => $pendingReviewCount,
                    'rejected' => Song::where('status', 'rejected')->count(),
                    'featured' => Song::where('is_featured', true)->count(),
                    'total_plays' => Song::sum('play_count'),
                    'total_downloads' => Song::sum('download_count'),
                    'total_likes' => Song::sum('like_count'),
                    'isrc_assigned' => $isrcAssigned,
                    'isrc_ready' => $isrcReady,
                    'isrc_blocked' => $isrcBlocked,
                ],
            ]);
        }, 'Failed to fetch song statistics.');
    }

    /**
     * Show a single song with all relationships.
     */
    public function show(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $song = Song::with(['artist', 'album', 'primaryGenre', 'genres'])->findOrFail($id);

            // Build a comprehensive response for admin
            $data = (new SongResource($song))->toArray(request());

            // Add admin-specific fields
            $data['status'] = $song->status;
            $data['visibility'] = $song->visibility;
            $data['description'] = $song->description;
            $data['lyrics'] = $song->lyrics;
            $data['is_downloadable'] = (bool) $song->is_downloadable;
            $data['is_streamable'] = (bool) $song->is_streamable;
            $data['processing_status'] = $song->processing_status;
            $data['track_number'] = $song->track_number;
            $data['disc_number'] = $song->disc_number;
            $data['isrc'] = $song->isrc_code;
            $data['bpm'] = $song->bpm ?? null;
            $data['key'] = $song->key_signature ?? null;
            $data['credits'] = $song->credits;
            $data['composer'] = $song->composer;
            $data['producer'] = $song->producer;
            $data['copyright_holder'] = $song->copyright_holder;
            $data['copyright_year'] = $song->copyright_year;
            $data['audio_file_url'] = StorageHelper::url($song->audio_file_320 ?? $song->audio_file_original);
            $data['file_size_bytes'] = $song->file_size_bytes;
            $data['file_format'] = $song->file_format;
            $data['bitrate_original'] = $song->bitrate_original;
            $data['sample_rate'] = $song->sample_rate;
            $data['featured_artists'] = $song->featured_artists;
            $data['approved_at'] = $song->approved_at;
            $data['review_notes'] = $song->review_notes;
            $data['rejection_reason'] = $song->rejection_reason;
            $data['meta_title'] = $song->meta_title ?? null;
            $data['meta_description'] = $song->meta_description ?? null;
            $data['artist_id'] = $song->artist_id;
            $data['album_id'] = $song->album_id;
            $data['primary_genre_id'] = $song->primary_genre_id;
            $data['genre_ids'] = $song->genres->pluck('id')->toArray();

            return response()->json(['data' => $data]);
        }, 'Failed to fetch song details.');
    }

    /**
     * Store a new song.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'artist_id' => 'required|exists:artists,id',
                'status' => 'nullable|string|in:draft,pending,published,rejected',
                'explicit' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'slug' => 'nullable|string|max:255',
                'album_id' => 'nullable|exists:albums,id',
                'duration_seconds' => 'nullable|string',
                'release_date' => 'nullable|date',
                'track_number' => 'nullable|integer|min:1',
                'disc_number' => 'nullable|integer|min:1',
                'lyrics' => 'nullable|string',
                'description' => 'nullable|string|max:2000',
                'isrc' => 'nullable|string|max:20',
                'bpm' => 'nullable|integer|min:1|max:999',
                'key' => 'nullable|string|max:10',
                'composer' => 'nullable|string|max:255',
                'producer' => 'nullable|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'is_free' => 'nullable|boolean',
                'is_downloadable' => 'nullable|boolean',
                'genre_ids' => 'nullable|array',
                'genre_ids.*' => 'exists:genres,id',
                'featured_artists' => 'nullable|array',
                'featured_artists.*' => 'exists:artists,id',
                'credits' => 'nullable|json',
                'audio_file' => 'required|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:'.(int) ceil((int) config('music.storage.limits.max_audio_size', 500 * 1024 * 1024) / 1024),
                'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
                'artwork' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            ]);

            // Generate slug if not provided
            $slug = $validated['slug'] ?? Str::slug($validated['title']);
            $originalSlug = $slug;
            $counter = 1;
            while (Song::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $originalSlug.'-'.$counter++;
            }

            // Handle audio file upload
            $audioPath = null;
            if ($request->hasFile('audio_file')) {
                $audioFile = $request->file('audio_file');
                $this->assertValidUpload($audioFile, 'audio_file');
                $audioPath = $this->storeUploadedFile($audioFile, 'songs/audio');
            }

            $audioMetadata = $audioPath
                ? $this->audioMetadataService->extractFromStoragePath($audioPath, StorageHelper::mediaDisk())
                : null;

            // Handle cover image upload
            $artworkPath = null;
            $coverImage = $request->file('cover_image') ?? $request->file('artwork');
            if ($coverImage) {
                $this->assertValidUpload($coverImage, 'cover_image');
                $artworkPath = $this->storeUploadedFile($coverImage, 'songs/artwork');
            }

            $requestedDurationSeconds = $this->parseDurationInput($validated['duration_seconds'] ?? null);
            $durationSeconds = ($audioMetadata['duration_seconds'] ?? 0) > 0
                ? (int) $audioMetadata['duration_seconds']
                : ($requestedDurationSeconds ?? 0);

            $song = Song::create($this->persistableSongAttributes([
                'title' => $validated['title'],
                'slug' => $slug,
                'artist_id' => $validated['artist_id'],
                'album_id' => $validated['album_id'] ?? null,
                'primary_genre_id' => $validated['genre_ids'][0] ?? null,
                'status' => $validated['status'] ?? 'draft',
                'is_explicit' => $request->boolean('explicit'),
                'is_featured' => $request->boolean('is_featured'),
                'description' => $validated['description'] ?? null,
                'lyrics' => $validated['lyrics'] ?? null,
                'release_date' => $validated['release_date'] ?? null,
                'track_number' => $validated['track_number'] ?? null,
                'disc_number' => $validated['disc_number'] ?? null,
                'isrc_code' => $validated['isrc'] ?? null,
                'composer' => $validated['composer'] ?? null,
                'producer' => $validated['producer'] ?? null,
                'price' => $validated['price'] ?? 0,
                'is_free' => $request->boolean('is_free', true),
                'is_downloadable' => $request->boolean('is_downloadable', true),
                'credits' => ! empty($validated['credits']) ? json_decode($validated['credits'], true) : null,
                'featured_artists' => $validated['featured_artists'] ?? null,
                'duration_seconds' => $durationSeconds,
                'audio_file_original' => $audioPath,
                'audio_file_320' => $audioPath, // In production, transcode separately
                'artwork' => $artworkPath,
                'file_format' => $audioMetadata['file_format'] ?? ($request->hasFile('audio_file') ? $request->file('audio_file')->getClientOriginalExtension() : null),
                'file_size_bytes' => $audioMetadata['file_size_bytes'] ?? ($request->hasFile('audio_file') ? $request->file('audio_file')->getSize() : null),
                'bitrate_original' => $audioMetadata['bitrate_original'] ?? null,
                'sample_rate' => $audioMetadata['sample_rate'] ?? null,
                'bpm' => $validated['bpm'] ?? null,
                'key_signature' => $validated['key'] ?? null,
                'processing_status' => 'completed',
                'visibility' => 'public',
            ]));

            // Sync genres
            if (! empty($validated['genre_ids'])) {
                $song->genres()->sync($validated['genre_ids']);
            }

            $song->load(['artist', 'album', 'primaryGenre']);
            $this->notifySongStatusTransition($song, null);

            return response()->json([
                'success' => true,
                'message' => 'Song created successfully',
                'data' => new SongResource($song),
            ], 201);
        }, 'Failed to create song.');
    }

    /**
     * Update an existing song.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $song = Song::findOrFail($id);
            $previousStatus = $song->status;

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'artist_id' => 'sometimes|required|exists:artists,id',
                'status' => 'nullable|string|in:draft,pending,published,rejected',
                'explicit' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'slug' => 'nullable|string|max:255',
                'album_id' => 'nullable|exists:albums,id',
                'duration_seconds' => 'nullable|string',
                'release_date' => 'nullable|date',
                'track_number' => 'nullable|integer|min:1',
                'disc_number' => 'nullable|integer|min:1',
                'lyrics' => 'nullable|string',
                'description' => 'nullable|string|max:2000',
                'isrc' => 'nullable|string|max:20',
                'bpm' => 'nullable|integer|min:1|max:999',
                'key' => 'nullable|string|max:10',
                'composer' => 'nullable|string|max:255',
                'producer' => 'nullable|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'is_free' => 'nullable|boolean',
                'is_downloadable' => 'nullable|boolean',
                'genre_ids' => 'nullable|array',
                'genre_ids.*' => 'exists:genres,id',
                'featured_artists' => 'nullable|array',
                'featured_artists.*' => 'exists:artists,id',
                'credits' => 'nullable|json',
                'audio_file' => 'nullable|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:'.(int) ceil((int) config('music.storage.limits.max_audio_size', 500 * 1024 * 1024) / 1024),
                'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
                'artwork' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            ]);

            $updateData = [];

            if (isset($validated['title'])) {
                $updateData['title'] = $validated['title'];
            }
            if (isset($validated['artist_id'])) {
                $updateData['artist_id'] = $validated['artist_id'];
            }
            if (isset($validated['status'])) {
                $updateData['status'] = $validated['status'];
            }
            if (isset($validated['album_id'])) {
                $updateData['album_id'] = $validated['album_id'];
            }
            if (isset($validated['description'])) {
                $updateData['description'] = $validated['description'];
            }
            if (isset($validated['lyrics'])) {
                $updateData['lyrics'] = $validated['lyrics'];
            }
            if (isset($validated['release_date'])) {
                $updateData['release_date'] = $validated['release_date'];
            }
            if (isset($validated['track_number'])) {
                $updateData['track_number'] = $validated['track_number'];
            }
            if (isset($validated['disc_number'])) {
                $updateData['disc_number'] = $validated['disc_number'];
            }
            if (isset($validated['isrc'])) {
                $updateData['isrc_code'] = $validated['isrc'];
            }
            if (isset($validated['featured_artists'])) {
                $updateData['featured_artists'] = $validated['featured_artists'];
            }
            if (isset($validated['composer'])) {
                $updateData['composer'] = $validated['composer'];
            }
            if (isset($validated['producer'])) {
                $updateData['producer'] = $validated['producer'];
            }
            if (isset($validated['price'])) {
                $updateData['price'] = $validated['price'];
            }

            if (isset($validated['bpm'])) {
                $updateData['bpm'] = $validated['bpm'];
            }
            if (isset($validated['key'])) {
                $updateData['key_signature'] = $validated['key'];
            }
            if ($request->has('is_free')) {
                $updateData['is_free'] = $request->boolean('is_free');
            }
            if ($request->has('is_downloadable')) {
                $updateData['is_downloadable'] = $request->boolean('is_downloadable');
            }

            if ($request->has('explicit')) {
                $updateData['is_explicit'] = $request->boolean('explicit');
            }
            if ($request->has('is_featured')) {
                $updateData['is_featured'] = $request->boolean('is_featured');
            }

            if (! empty($validated['credits'])) {
                $updateData['credits'] = json_decode($validated['credits'], true);
            }

            if (isset($validated['slug'])) {
                $slug = $validated['slug'];
                $existing = Song::withTrashed()->where('slug', $slug)->where('id', '!=', $id)->exists();
                if (! $existing) {
                    $updateData['slug'] = $slug;
                }
            }

            $requestedDurationSeconds = $this->parseDurationInput($validated['duration_seconds'] ?? null);
            if ($requestedDurationSeconds !== null) {
                $updateData['duration_seconds'] = $requestedDurationSeconds;
            }

            // Handle audio file replacement
            if ($request->hasFile('audio_file')) {
                $audioFile = $request->file('audio_file');
                $this->assertValidUpload($audioFile, 'audio_file');

                // Delete old files
                if ($song->audio_file_original) {
                    StorageHelper::delete($song->audio_file_original);
                }
                if ($song->audio_file_320 && $song->audio_file_320 !== $song->audio_file_original) {
                    StorageHelper::delete($song->audio_file_320);
                }

                $audioPath = $this->storeUploadedFile($audioFile, 'songs/audio');
                $audioMetadata = $this->audioMetadataService->extractFromStoragePath($audioPath, StorageHelper::mediaDisk());
                $updateData['audio_file_original'] = $audioPath;
                $updateData['audio_file_320'] = $audioPath;
                $updateData['file_format'] = $audioMetadata['file_format'] ?? $audioFile->getClientOriginalExtension();
                $updateData['file_size_bytes'] = $audioMetadata['file_size_bytes'] ?? $audioFile->getSize();
                $updateData['bitrate_original'] = $audioMetadata['bitrate_original'] ?? null;
                $updateData['sample_rate'] = $audioMetadata['sample_rate'] ?? null;
                $updateData['duration_seconds'] = ($audioMetadata['duration_seconds'] ?? 0) > 0
                    ? (int) $audioMetadata['duration_seconds']
                    : ($requestedDurationSeconds ?? 0);
            }

            // Handle cover image replacement
            $coverImage = $request->file('cover_image') ?? $request->file('artwork');
            if ($coverImage) {
                $this->assertValidUpload($coverImage, 'cover_image');

                if ($song->artwork) {
                    StorageHelper::delete($song->artwork);
                }
                $updateData['artwork'] = $this->storeUploadedFile($coverImage, 'songs/artwork');
            }

            // Update genre
            if (! empty($validated['genre_ids'])) {
                $updateData['primary_genre_id'] = $validated['genre_ids'][0];
                $song->genres()->sync($validated['genre_ids']);
            }

            $song->update($this->persistableSongAttributes($updateData));
            $song->load(['artist', 'album', 'primaryGenre', 'genres']);
            $this->notifySongStatusTransition($song, $previousStatus, $updateData['rejection_reason'] ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Song updated successfully',
                'data' => new SongResource($song),
            ]);
        }, 'Failed to update song.');
    }

    /**
     * Delete a song.
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $song = Song::findOrFail($id);

            // Delete associated files
            if ($song->audio_file_original) {
                StorageHelper::delete($song->audio_file_original);
            }
            if ($song->audio_file_320 && $song->audio_file_320 !== $song->audio_file_original) {
                StorageHelper::delete($song->audio_file_320);
            }
            if ($song->audio_file_128) {
                StorageHelper::delete($song->audio_file_128);
            }
            if ($song->artwork) {
                StorageHelper::delete($song->artwork);
            }

            $song->delete();

            return response()->json(['success' => true, 'message' => 'Song deleted successfully']);
        }, 'Failed to delete song.');
    }

    /**
     * Toggle song publish/draft status.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $song = Song::findOrFail($id);
            $previousStatus = $song->status;

            $newStatus = $song->status === 'published' ? 'draft' : 'published';
            $song->update([
                'status' => $newStatus,
                'published_at' => $newStatus === 'published' ? now() : null,
            ]);
            $song->refresh();
            $this->notifySongStatusTransition($song, $previousStatus);

            return response()->json([
                'success' => true,
                'message' => "Song status changed to {$newStatus}",
                'data' => ['status' => $newStatus],
            ]);
        }, 'Failed to toggle song status.');
    }

    /**
     * Toggle featured status.
     */
    public function toggleFeatured(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $song = Song::findOrFail($id);
            $song->update(['is_featured' => ! $song->is_featured]);

            return response()->json([
                'success' => true,
                'message' => $song->is_featured ? 'Song marked as featured' : 'Song removed from featured',
                'data' => ['is_featured' => $song->is_featured],
            ]);
        }, 'Failed to toggle featured status.');
    }

    /**
     * Bulk approve songs.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $request->validate([
                'song_ids' => 'required|array|min:1',
                'song_ids.*' => 'exists:songs,id',
            ]);

            $songs = Song::with('artist.user')->whereIn('id', $request->song_ids)->get();
            $previousStatuses = $songs->mapWithKeys(fn (Song $song) => [$song->id => $song->status]);

            $count = Song::whereIn('id', $request->song_ids)
                ->update([
                    'status' => 'published',
                    'distribution_status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'published_at' => now(),
                ]);

            $approvedSongs = Song::with('artist.user')
                ->whereIn('id', $request->song_ids)
                ->get();

            $isrcAssignedCount = 0;
            $isrcAlreadyAssignedCount = 0;
            $isrcBlockedCount = 0;

            foreach ($approvedSongs as $song) {
                $previousStatus = $previousStatuses[$song->id] ?? null;

                if ($song->hasIsrcAssigned()) {
                    $isrcAlreadyAssignedCount++;
                } elseif ($song->canAssignIsrc()) {
                    try {
                        $this->isrcService->assignToSong($song);
                        $song->refresh();
                        $isrcAssignedCount++;
                    } catch (\Throwable $exception) {
                        Log::warning('SongsApiController bulkApprove ISRC assignment failed', [
                            'song_id' => $song->id,
                            'error' => $exception->getMessage(),
                        ]);
                        $isrcBlockedCount++;
                    }
                } else {
                    $isrcBlockedCount++;
                }

                $this->notifySongStatusTransition($song, $previousStatus);
            }

            return response()->json([
                'success' => true,
                'message' => "{$count} song(s) approved and published",
                'data' => [
                    'count' => $count,
                    'approved_count' => $count,
                    'isrc_assigned_count' => $isrcAssignedCount,
                    'isrc_already_assigned_count' => $isrcAlreadyAssignedCount,
                    'isrc_blocked_count' => $isrcBlockedCount,
                ],
            ]);
        }, 'Failed to bulk approve songs.');
    }

    /**
     * Bulk reject songs.
     */
    public function bulkReject(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $request->validate([
                'song_ids' => 'required|array|min:1',
                'song_ids.*' => 'exists:songs,id',
                'reason' => 'nullable|string|max:500',
            ]);

            $reason = $request->input('reason', 'Rejected by admin');

            // Load songs with their artist/user before updating so we can notify
            $songs = Song::with('artist.user')->whereIn('id', $request->song_ids)->get();

            $count = Song::whereIn('id', $request->song_ids)
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ]);

            // Notify each artist that their song was rejected
            foreach ($songs as $song) {
                $song->status = 'rejected';
                $song->rejection_reason = $reason;
                $this->notifySongStatusTransition($song, 'pending', $reason);
            }

            return response()->json([
                'success' => true,
                'message' => "{$count} song(s) rejected",
                'data' => ['count' => $count],
            ]);
        }, 'Failed to bulk reject songs.');
    }

    /**
     * Get play history for a song.
     */
    public function playHistory(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $song = Song::findOrFail($id);

            $history = $song->playHistory()
                ->with('user:id,name,username,avatar')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($play) {
                    return [
                        'id' => $play->id,
                        'user' => $play->user ? [
                            'id' => $play->user->id,
                            'name' => $play->user->name,
                            'username' => $play->user->username,
                            'avatar' => StorageHelper::avatarUrl($play->user->avatar, $play->user->name),
                        ] : null,
                        'played_at' => $play->created_at->toIso8601String(),
                        'duration_listened' => $play->duration_listened ?? null,
                        'completed' => $play->completed ?? false,
                    ];
                });

            return response()->json(['success' => true, 'data' => $history]);
        }, 'Failed to fetch play history.');
    }
}
