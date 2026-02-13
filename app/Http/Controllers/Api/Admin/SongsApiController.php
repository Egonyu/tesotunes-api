<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class SongsApiController extends Controller
{
    /**
     * List songs with filtering, searching, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Song::with(['artist', 'album', 'primaryGenre']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhereHas('artist', function ($aq) use ($search) {
                      $aq->where('stage_name', 'like', "%{$search}%");
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
            'data' => SongResource::collection($songs->items()),
            'meta' => [
                'current_page' => $songs->currentPage(),
                'last_page' => $songs->lastPage(),
                'per_page' => $songs->perPage(),
                'total' => $songs->total(),
            ],
        ]);
    }

    /**
     * Get song statistics for admin dashboard.
     */
    public function statistics(): JsonResponse
    {
        return response()->json([
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
    }

    /**
     * Show a single song with all relationships.
     */
    public function show(int $id): JsonResponse
    {
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
        $data['key'] = $song->key ?? null;
        $data['credits'] = $song->credits;
        $data['composer'] = $song->composer;
        $data['producer'] = $song->producer;
        $data['copyright_holder'] = $song->copyright_holder;
        $data['copyright_year'] = $song->copyright_year;
        $data['cover_url'] = $song->artwork ? url('storage/' . $song->artwork) : null;
        $data['audio_file_url'] = $song->audio_file_320 ? url('storage/' . $song->audio_file_320) : ($song->audio_file_original ? url('storage/' . $song->audio_file_original) : null);
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
    }

    /**
     * Store a new song.
     */
    public function store(Request $request): JsonResponse
    {
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
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'genre_ids' => 'nullable|array',
            'genre_ids.*' => 'exists:genres,id',
            'featured_artists' => 'nullable|array',
            'featured_artists.*' => 'exists:artists,id',
            'credits' => 'nullable|json',
            'audio_file' => 'required|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:51200',
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        // Generate slug if not provided
        $slug = $validated['slug'] ?? Str::slug($validated['title']);
        $originalSlug = $slug;
        $counter = 1;
        while (Song::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        // Handle audio file upload
        $audioPath = null;
        if ($request->hasFile('audio_file')) {
            $audioFile = $request->file('audio_file');
            $audioPath = $audioFile->store('songs/audio', 'public');
        }

        // Handle cover image upload
        $artworkPath = null;
        if ($request->hasFile('cover_image')) {
            $artworkPath = $request->file('cover_image')->store('songs/artwork', 'public');
        }

        // Parse duration string to seconds
        $durationSeconds = null;
        if (!empty($validated['duration'])) {
            $parts = explode(':', $validated['duration']);
            if (count($parts) === 2) {
                $durationSeconds = (int) $parts[0] * 60 + (int) $parts[1];
            } elseif (is_numeric($validated['duration'])) {
                $durationSeconds = (int) $validated['duration'];
            }
        }

        $song = Song::create([
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
            'credits' => !empty($validated['credits']) ? json_decode($validated['credits'], true) : null,
            'featured_artists' => $validated['featured_artists'] ?? null,
            'duration_seconds' => $durationSeconds,
            'audio_file_original' => $audioPath,
            'audio_file_320' => $audioPath, // In production, transcode separately
            'artwork' => $artworkPath,
            'file_format' => $request->hasFile('audio_file') ? $request->file('audio_file')->getClientOriginalExtension() : null,
            'file_size_bytes' => $request->hasFile('audio_file') ? $request->file('audio_file')->getSize() : null,
            'processing_status' => 'completed',
            'visibility' => 'public',
        ]);

        // Sync genres
        if (!empty($validated['genre_ids'])) {
            $song->genres()->sync($validated['genre_ids']);
        }

        $song->load(['artist', 'album', 'primaryGenre']);

        return response()->json([
            'message' => 'Song created successfully',
            'data' => new SongResource($song),
        ], 201);
    }

    /**
     * Update an existing song.
     */
    public function update(Request $request, int $id): JsonResponse
    {
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
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'genre_ids' => 'nullable|array',
            'genre_ids.*' => 'exists:genres,id',
            'featured_artists' => 'nullable|array',
            'featured_artists.*' => 'exists:artists,id',
            'credits' => 'nullable|json',
            'audio_file' => 'nullable|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:51200',
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        $updateData = [];

        if (isset($validated['title'])) $updateData['title'] = $validated['title'];
        if (isset($validated['artist_id'])) $updateData['artist_id'] = $validated['artist_id'];
        if (isset($validated['status'])) $updateData['status'] = $validated['status'];
        if (isset($validated['album_id'])) $updateData['album_id'] = $validated['album_id'];
        if (isset($validated['description'])) $updateData['description'] = $validated['description'];
        if (isset($validated['lyrics'])) $updateData['lyrics'] = $validated['lyrics'];
        if (isset($validated['release_date'])) $updateData['release_date'] = $validated['release_date'];
        if (isset($validated['track_number'])) $updateData['track_number'] = $validated['track_number'];
        if (isset($validated['disc_number'])) $updateData['disc_number'] = $validated['disc_number'];
        if (isset($validated['isrc'])) $updateData['isrc_code'] = $validated['isrc'];
        if (isset($validated['featured_artists'])) $updateData['featured_artists'] = $validated['featured_artists'];

        if ($request->has('explicit')) $updateData['is_explicit'] = $request->boolean('explicit');
        if ($request->has('is_featured')) $updateData['is_featured'] = $request->boolean('is_featured');

        if (!empty($validated['credits'])) {
            $updateData['credits'] = json_decode($validated['credits'], true);
        }

        if (isset($validated['slug'])) {
            $slug = $validated['slug'];
            $existing = Song::where('slug', $slug)->where('id', '!=', $id)->exists();
            if (!$existing) {
                $updateData['slug'] = $slug;
            }
        }

        // Parse duration
        if (!empty($validated['duration'])) {
            $parts = explode(':', $validated['duration']);
            if (count($parts) === 2) {
                $updateData['duration_seconds'] = (int) $parts[0] * 60 + (int) $parts[1];
            } elseif (is_numeric($validated['duration'])) {
                $updateData['duration_seconds'] = (int) $validated['duration'];
            }
        }

        // Handle audio file replacement
        if ($request->hasFile('audio_file')) {
            // Delete old files
            if ($song->audio_file_original) {
                Storage::disk('public')->delete($song->audio_file_original);
            }
            if ($song->audio_file_320 && $song->audio_file_320 !== $song->audio_file_original) {
                Storage::disk('public')->delete($song->audio_file_320);
            }

            $audioPath = $request->file('audio_file')->store('songs/audio', 'public');
            $updateData['audio_file_original'] = $audioPath;
            $updateData['audio_file_320'] = $audioPath;
            $updateData['file_format'] = $request->file('audio_file')->getClientOriginalExtension();
            $updateData['file_size_bytes'] = $request->file('audio_file')->getSize();
        }

        // Handle cover image replacement
        if ($request->hasFile('cover_image')) {
            if ($song->artwork) {
                Storage::disk('public')->delete($song->artwork);
            }
            $updateData['artwork'] = $request->file('cover_image')->store('songs/artwork', 'public');
        }

        // Update genre
        if (!empty($validated['genre_ids'])) {
            $updateData['primary_genre_id'] = $validated['genre_ids'][0];
            $song->genres()->sync($validated['genre_ids']);
        }

        $song->update($updateData);
        $song->load(['artist', 'album', 'primaryGenre', 'genres']);

        return response()->json([
            'message' => 'Song updated successfully',
            'data' => new SongResource($song),
        ]);
    }

    /**
     * Delete a song.
     */
    public function destroy(int $id): JsonResponse
    {
        $song = Song::findOrFail($id);

        // Delete associated files
        if ($song->audio_file_original) {
            Storage::disk('public')->delete($song->audio_file_original);
        }
        if ($song->audio_file_320 && $song->audio_file_320 !== $song->audio_file_original) {
            Storage::disk('public')->delete($song->audio_file_320);
        }
        if ($song->audio_file_128) {
            Storage::disk('public')->delete($song->audio_file_128);
        }
        if ($song->artwork) {
            Storage::disk('public')->delete($song->artwork);
        }

        $song->delete();

        return response()->json(['message' => 'Song deleted successfully']);
    }

    /**
     * Toggle song publish/draft status.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $song = Song::findOrFail($id);

        $newStatus = $song->status === 'published' ? 'draft' : 'published';
        $song->update([
            'status' => $newStatus,
            'published_at' => $newStatus === 'published' ? now() : null,
        ]);

        return response()->json([
            'message' => "Song status changed to {$newStatus}",
            'data' => ['status' => $newStatus],
        ]);
    }

    /**
     * Toggle featured status.
     */
    public function toggleFeatured(int $id): JsonResponse
    {
        $song = Song::findOrFail($id);
        $song->update(['is_featured' => !$song->is_featured]);

        return response()->json([
            'message' => $song->is_featured ? 'Song marked as featured' : 'Song removed from featured',
            'data' => ['is_featured' => $song->is_featured],
        ]);
    }

    /**
     * Bulk approve songs.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'song_ids' => 'required|array|min:1',
            'song_ids.*' => 'exists:songs,id',
        ]);

        $count = Song::whereIn('id', $request->song_ids)
            ->update([
                'status' => 'published',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'published_at' => now(),
            ]);

        return response()->json([
            'message' => "{$count} song(s) approved and published",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Bulk reject songs.
     */
    public function bulkReject(Request $request): JsonResponse
    {
        $request->validate([
            'song_ids' => 'required|array|min:1',
            'song_ids.*' => 'exists:songs,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $count = Song::whereIn('id', $request->song_ids)
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $request->input('reason', 'Rejected by admin'),
            ]);

        return response()->json([
            'message' => "{$count} song(s) rejected",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Get play history for a song.
     */
    public function playHistory(int $id): JsonResponse
    {
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
                        'avatar' => $play->user->avatar ? url('storage/' . $play->user->avatar) : null,
                    ] : null,
                    'played_at' => $play->created_at->toIso8601String(),
                    'duration_listened' => $play->duration_listened ?? null,
                    'completed' => $play->completed ?? false,
                ];
            });

        return response()->json(['data' => $history]);
    }
}
