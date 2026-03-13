<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Notifications\SongModerationNotification;
use App\Traits\HandlesApiErrors;
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
                $query->where('status', $status);
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

            return response()->json([
                'success' => true,
                'data' => SongResource::collection($songs->items()),
                'meta' => [
                    'current_page' => $songs->currentPage(),
                    'last_page' => $songs->lastPage(),
                    'per_page' => $songs->perPage(),
                    'total' => $songs->total(),
                ],
            ]);
        }, 'Failed to fetch songs.');
    }

    /**
     * Get song statistics for admin dashboard.
     */
    public function statistics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => Song::count(),
                    'published' => Song::where('status', 'published')->count(),
                    'draft' => Song::where('status', 'draft')->count(),
                    'pending' => Song::where('status', 'pending')->count(),
                    'rejected' => Song::where('status', 'rejected')->count(),
                    'featured' => Song::where('is_featured', true)->count(),
                    'total_plays' => Song::sum('play_count'),
                    'total_downloads' => Song::sum('download_count'),
                    'total_likes' => Song::sum('like_count'),
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
            $data['cover_url'] = StorageHelper::url($song->artwork);
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

            return response()->json(['success' => true, 'data' => $data]);
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
                'duration' => 'nullable|string',
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
                'audio_file' => 'required|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:51200',
                'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
                'artwork' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            ]);

            // Generate slug if not provided
            $slug = $validated['slug'] ?? Str::slug($validated['title']);
            $originalSlug = $slug;
            $counter = 1;
            while (Song::where('slug', $slug)->exists()) {
                $slug = $originalSlug.'-'.$counter++;
            }

            // Handle audio file upload
            $audioPath = null;
            if ($request->hasFile('audio_file')) {
                $audioFile = $request->file('audio_file');
                $this->assertValidUpload($audioFile, 'audio_file');
                $audioPath = $this->storeUploadedFile($audioFile, 'songs/audio');
            }

            // Handle cover image upload
            $artworkPath = null;
            $coverImage = $request->file('cover_image') ?? $request->file('artwork');
            if ($coverImage) {
                $this->assertValidUpload($coverImage, 'cover_image');
                $artworkPath = $this->storeUploadedFile($coverImage, 'songs/artwork');
            }

            // Parse duration string to seconds
            $durationSeconds = null;
            if (! empty($validated['duration'])) {
                $parts = explode(':', $validated['duration']);
                if (count($parts) === 2) {
                    $durationSeconds = (int) $parts[0] * 60 + (int) $parts[1];
                } elseif (is_numeric($validated['duration'])) {
                    $durationSeconds = (int) $validated['duration'];
                }
            }

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
                'duration_seconds' => $durationSeconds ?? 0,
                'audio_file_original' => $audioPath,
                'audio_file_320' => $audioPath, // In production, transcode separately
                'artwork' => $artworkPath,
                'file_format' => $request->hasFile('audio_file') ? $request->file('audio_file')->getClientOriginalExtension() : null,
                'file_size_bytes' => $request->hasFile('audio_file') ? $request->file('audio_file')->getSize() : null,
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

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'artist_id' => 'sometimes|required|exists:artists,id',
                'status' => 'nullable|string|in:draft,pending,published,rejected',
                'explicit' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'slug' => 'nullable|string|max:255',
                'album_id' => 'nullable|exists:albums,id',
                'duration' => 'nullable|string',
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
                'audio_file' => 'nullable|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:51200',
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
                $existing = Song::where('slug', $slug)->where('id', '!=', $id)->exists();
                if (! $existing) {
                    $updateData['slug'] = $slug;
                }
            }

            // Parse duration
            if (! empty($validated['duration'])) {
                $parts = explode(':', $validated['duration']);
                if (count($parts) === 2) {
                    $updateData['duration_seconds'] = (int) $parts[0] * 60 + (int) $parts[1];
                } elseif (is_numeric($validated['duration'])) {
                    $updateData['duration_seconds'] = (int) $validated['duration'];
                }
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
                $updateData['audio_file_original'] = $audioPath;
                $updateData['audio_file_320'] = $audioPath;
                $updateData['file_format'] = $audioFile->getClientOriginalExtension();
                $updateData['file_size_bytes'] = $audioFile->getSize();
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

            $newStatus = $song->status === 'published' ? 'draft' : 'published';
            $song->update([
                'status' => $newStatus,
                'published_at' => $newStatus === 'published' ? now() : null,
            ]);

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

            // Load songs with their artist/user before updating so we can notify
            $songs = Song::with('artist.user')->whereIn('id', $request->song_ids)->get();

            $count = Song::whereIn('id', $request->song_ids)
                ->update([
                    'status' => 'published',
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'published_at' => now(),
                ]);

            // Notify each artist that their song was approved
            foreach ($songs as $song) {
                $user = $song->artist?->user;
                if ($user) {
                    $user->notify(new SongModerationNotification($song, SongModerationNotification::APPROVED));
                }
            }

            return response()->json([
                'success' => true,
                'message' => "{$count} song(s) approved and published",
                'data' => ['count' => $count],
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
                $user = $song->artist?->user;
                if ($user) {
                    $user->notify(new SongModerationNotification($song, SongModerationNotification::REJECTED, $reason));
                }
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
