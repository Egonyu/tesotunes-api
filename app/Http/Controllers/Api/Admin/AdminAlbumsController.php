<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminAlbumsController extends Controller
{
    use HandlesApiErrors;

    private static ?array $albumTableColumns = null;

    private function storeUploadedFile(UploadedFile $file, string $directory): string
    {
        return StorageHelper::store(
            $file,
            $directory,
            Str::uuid().'.'.$file->getClientOriginalExtension()
        );
    }

    private function persistableAlbumAttributes(array $attributes): array
    {
        $columns = self::$albumTableColumns ??= array_flip(Schema::getColumnListing((new Album)->getTable()));

        return array_intersect_key($attributes, $columns);
    }

    private function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug !== '' ? $baseSlug : Str::lower(Str::random(10));
        $counter = 1;

        while (
            Album::query()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$counter++;
        }

        return $slug;
    }

    private function normalizeListStatus(Album $album): string
    {
        if ($album->status === 'published') {
            return 'published';
        }

        if (
            $album->status === 'upcoming'
            || ($album->release_date && $album->release_date->isFuture() && $album->status !== 'draft')
        ) {
            return 'upcoming';
        }

        return $album->status;
    }

    private function serializeListItem(Album $album): array
    {
        return [
            'id' => $album->id,
            'title' => $album->title,
            'artist' => $album->artist ? [
                'id' => $album->artist->id,
                'name' => $album->artist->stage_name,
            ] : null,
            'artwork_url' => StorageHelper::artworkUrl($album->artwork),
            'songs_count' => (int) ($album->songs_count ?? $album->songs()->count()),
            'release_date' => $album->release_date?->toIso8601String(),
            'total_plays' => (int) ($album->play_count ?? 0),
            'status' => $this->normalizeListStatus($album),
        ];
    }

    private function serializeDetailItem(Album $album): array
    {
        $songs = $album->songs->map(fn (Song $song) => [
            'id' => (string) $song->id,
            'title' => $song->title,
            'slug' => $song->slug,
            'duration' => (int) ($song->duration_seconds ?? 0),
            'duration_seconds' => (int) ($song->duration_seconds ?? 0),
            'track_number' => (int) ($song->track_number ?? 0),
            'disc_number' => (int) ($song->disc_number ?? 1),
            'plays' => (int) ($song->play_count ?? 0),
            'status' => $song->status,
        ])->values();

        $genres = collect();
        if ($album->primaryGenre) {
            $genres->push([
                'id' => (string) $album->primaryGenre->id,
                'name' => $album->primaryGenre->name,
            ]);
        }

        return [
            'id' => (string) $album->id,
            'title' => $album->title,
            'slug' => $album->slug,
            'description' => $album->description ?? '',
            'album_type' => $album->album_type ?? 'album',
            'release_date' => $album->release_date?->toIso8601String(),
            'label' => $album->record_label,
            'copyright' => $album->copyright,
            'upc' => $album->upc_code,
            'total_duration' => (int) ($album->total_duration_seconds ?? $songs->sum('duration_seconds')),
            'total_tracks' => (int) ($album->total_tracks ?: $songs->count()),
            'plays' => (int) ($album->play_count ?? 0),
            'likes' => (int) ($album->like_count ?? 0),
            'status' => $album->status,
            'is_featured' => (bool) $album->is_featured,
            'explicit' => (bool) $album->is_explicit,
            'cover_url' => StorageHelper::artworkUrl($album->artwork),
            'artwork_url' => StorageHelper::artworkUrl($album->artwork),
            'artist' => [
                'id' => (string) $album->artist?->id,
                'name' => $album->artist?->stage_name ?? 'Unknown Artist',
                'slug' => $album->artist?->slug ?? '',
            ],
            'featured_artists' => [],
            'genres' => $genres->values(),
            'songs' => $songs,
            'meta_title' => null,
            'meta_description' => null,
            'created_at' => $album->created_at?->toIso8601String(),
            'updated_at' => $album->updated_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->input('per_page', 20), 100);
            $status = $request->input('status');
            $search = trim((string) $request->input('search', ''));

            $albums = Album::query()
                ->with(['artist'])
                ->withCount('songs')
                ->when($search !== '', function ($query) use ($search) {
                    $escapedSearch = addcslashes($search, '%_');
                    $query->where(function ($subQuery) use ($escapedSearch) {
                        $subQuery->where('title', 'like', "%{$escapedSearch}%")
                            ->orWhere('slug', 'like', "%{$escapedSearch}%")
                            ->orWhereHas('artist', function ($artistQuery) use ($escapedSearch) {
                                $artistQuery->where('stage_name', 'like', "%{$escapedSearch}%");
                            });
                    });
                })
                ->when($status && $status !== 'all', function ($query) use ($status) {
                    if ($status === 'released') {
                        $query->where('status', 'published');
                    } elseif ($status === 'upcoming') {
                        $query->where(function ($upcomingQuery) {
                            $upcomingQuery->where('status', 'upcoming')
                                ->orWhereDate('release_date', '>', now()->toDateString());
                        });
                    } else {
                        $query->where('status', $status);
                    }
                })
                ->orderByDesc('release_date')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => collect($albums->items())->map(fn (Album $album) => $this->serializeListItem($album))->values(),
                'meta' => [
                    'current_page' => $albums->currentPage(),
                    'last_page' => $albums->lastPage(),
                    'per_page' => $albums->perPage(),
                    'total' => $albums->total(),
                ],
            ]);
        }, 'Failed to fetch albums.');
    }

    public function statistics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $releasedCount = Album::where('status', 'published')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => Album::count(),
                    'released' => $releasedCount,
                    'published' => $releasedCount,
                    'pending' => Album::where('status', 'pending')->count(),
                    'draft' => Album::where('status', 'draft')->count(),
                    'upcoming' => Album::where('status', 'upcoming')
                        ->orWhereDate('release_date', '>', now()->toDateString())
                        ->count(),
                ],
            ]);
        }, 'Failed to fetch album statistics.');
    }

    public function show(string $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $album = Album::query()
                ->with(['artist', 'primaryGenre', 'songs' => fn ($query) => $query->orderBy('track_number')])
                ->where(function ($query) use ($id) {
                    $query->where('id', $id)
                        ->orWhere('slug', $id)
                        ->orWhere('uuid', $id);
                })
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $this->serializeDetailItem($album),
            ]);
        }, 'Failed to fetch album details.');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:albums,slug',
                'artist_id' => 'required|exists:artists,id',
                'genre_ids' => 'nullable|array',
                'genre_ids.*' => 'exists:genres,id',
                'featured_artists' => 'nullable|array',
                'featured_artists.*' => 'exists:artists,id',
                'release_date' => 'nullable|date',
                'album_type' => 'nullable|in:album,ep,single,compilation,live,remix',
                'description' => 'nullable|string',
                'label' => 'nullable|string|max:255',
                'copyright' => 'nullable|string|max:255',
                'upc' => 'nullable|string|max:255',
                'status' => 'nullable|in:draft,pending,published,upcoming',
                'is_featured' => 'nullable|boolean',
                'explicit' => 'nullable|boolean',
                'cover_image' => 'nullable|image|max:10240',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
            ]);

            $album = Album::create($this->persistableAlbumAttributes([
                'uuid' => (string) Str::uuid(),
                'artist_id' => (int) $validated['artist_id'],
                'title' => $validated['title'],
                'slug' => $validated['slug'] ?? $this->generateUniqueSlug($validated['title']),
                'description' => $validated['description'] ?? null,
                'artwork' => $request->hasFile('cover_image')
                    ? $this->storeUploadedFile($request->file('cover_image'), 'albums/artwork')
                    : null,
                'album_type' => $validated['album_type'] ?? 'album',
                'type' => $validated['album_type'] ?? 'album',
                'primary_genre_id' => $validated['genre_ids'][0] ?? null,
                'release_date' => $validated['release_date'] ?? null,
                'status' => $validated['status'] ?? 'draft',
                'is_explicit' => $request->boolean('explicit'),
                'is_featured' => $request->boolean('is_featured'),
                'upc_code' => $validated['upc'] ?? null,
                'copyright' => $validated['copyright'] ?? null,
                'record_label' => $validated['label'] ?? null,
                'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
            ]));

            $album->load(['artist', 'primaryGenre', 'songs']);

            return response()->json([
                'success' => true,
                'message' => 'Album created successfully.',
                'data' => $this->serializeDetailItem($album),
            ], 201);
        }, 'Failed to create album.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $album = Album::with(['artist', 'primaryGenre', 'songs'])->findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:albums,slug,'.$album->id,
                'artist_id' => 'sometimes|required|exists:artists,id',
                'genre_ids' => 'nullable|array',
                'genre_ids.*' => 'exists:genres,id',
                'featured_artists' => 'nullable|array',
                'featured_artists.*' => 'exists:artists,id',
                'release_date' => 'nullable|date',
                'album_type' => 'nullable|in:album,ep,single,compilation,live,remix',
                'description' => 'nullable|string',
                'label' => 'nullable|string|max:255',
                'copyright' => 'nullable|string|max:255',
                'upc' => 'nullable|string|max:255',
                'status' => 'nullable|in:draft,pending,published,upcoming',
                'is_featured' => 'nullable|boolean',
                'explicit' => 'nullable|boolean',
                'cover_image' => 'nullable|image|max:10240',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
            ]);

            $updateData = [];

            if (array_key_exists('title', $validated)) {
                $updateData['title'] = $validated['title'];
            }
            if (array_key_exists('slug', $validated)) {
                $updateData['slug'] = $validated['slug'] ?: $this->generateUniqueSlug($validated['title'] ?? $album->title, $album->id);
            }
            if (array_key_exists('artist_id', $validated)) {
                $updateData['artist_id'] = (int) $validated['artist_id'];
            }
            if (array_key_exists('description', $validated)) {
                $updateData['description'] = $validated['description'];
            }
            if (array_key_exists('album_type', $validated)) {
                $updateData['album_type'] = $validated['album_type'];
                $updateData['type'] = $validated['album_type'];
            }
            if (array_key_exists('release_date', $validated)) {
                $updateData['release_date'] = $validated['release_date'];
            }
            if (array_key_exists('label', $validated)) {
                $updateData['record_label'] = $validated['label'];
            }
            if (array_key_exists('copyright', $validated)) {
                $updateData['copyright'] = $validated['copyright'];
            }
            if (array_key_exists('upc', $validated)) {
                $updateData['upc_code'] = $validated['upc'];
            }
            if (array_key_exists('genre_ids', $validated)) {
                $updateData['primary_genre_id'] = $validated['genre_ids'][0] ?? null;
            }
            if (array_key_exists('status', $validated)) {
                $updateData['status'] = $validated['status'];
                if ($validated['status'] === 'published' && ! $album->published_at) {
                    $updateData['published_at'] = now();
                }
                if ($validated['status'] !== 'published') {
                    $updateData['published_at'] = null;
                }
            }
            if ($request->has('is_featured')) {
                $updateData['is_featured'] = $request->boolean('is_featured');
            }
            if ($request->has('explicit')) {
                $updateData['is_explicit'] = $request->boolean('explicit');
            }

            if ($request->hasFile('cover_image')) {
                if ($album->artwork) {
                    StorageHelper::delete($album->artwork);
                }

                $updateData['artwork'] = $this->storeUploadedFile($request->file('cover_image'), 'albums/artwork');
            }

            $album->update($this->persistableAlbumAttributes($updateData));
            $album->load(['artist', 'primaryGenre', 'songs' => fn ($query) => $query->orderBy('track_number')]);

            return response()->json([
                'success' => true,
                'message' => 'Album updated successfully.',
                'data' => $this->serializeDetailItem($album),
            ]);
        }, 'Failed to update album.');
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $album = Album::findOrFail($id);

            DB::transaction(function () use ($album) {
                Song::where('album_id', $album->id)->update(['album_id' => null]);

                if ($album->artwork) {
                    StorageHelper::delete($album->artwork);
                }

                $album->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Album deleted successfully.',
            ]);
        }, 'Failed to delete album.');
    }

    public function toggleStatus(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $album = Album::findOrFail($id);
            $nextStatus = $album->status === 'published' ? 'draft' : 'published';

            $album->update($this->persistableAlbumAttributes([
                'status' => $nextStatus,
                'published_at' => $nextStatus === 'published' ? now() : null,
            ]));

            return response()->json([
                'success' => true,
                'message' => "Album status changed to {$nextStatus}.",
                'data' => [
                    'status' => $nextStatus,
                ],
            ]);
        }, 'Failed to toggle album status.');
    }
}
