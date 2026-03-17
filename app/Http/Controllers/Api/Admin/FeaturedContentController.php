<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\FeaturedContent;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FeaturedContentController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/featured
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            if (! Schema::hasTable('featured_content')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $items = FeaturedContent::query()
                ->when($request->input('status') === 'active', fn ($query) => $query->where('is_active', true))
                ->when($request->input('status') === 'inactive', fn ($query) => $query->where('is_active', false))
                ->orderBy('sort_order')
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $items->map(fn (FeaturedContent $item) => $this->serializeItem($item))->values(),
            ]);
        }, 'Failed to retrieve featured content.');
    }

    /**
     * POST /api/admin/featured
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'subtitle' => 'required|string|max:500',
                'link' => 'required|string|max:1000',
                'type' => 'required|in:song,album,artist,playlist,event,custom',
                'image' => 'nullable|image|max:5120',
                'image_url' => 'nullable|string|max:1000',
                'song_id' => 'nullable|exists:songs,id',
                'album_id' => 'nullable|exists:albums,id',
                'artist_id' => 'nullable|exists:artists,id',
                'event_id' => 'nullable|exists:events,id',
                'playlist_id' => 'nullable|exists:playlists,id',
                'is_active' => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
            ]);

            $item = FeaturedContent::create([
                'uuid' => (string) Str::uuid(),
                'title' => $validated['title'],
                'subtitle' => $validated['subtitle'],
                'image_path' => $this->resolveImagePath($request, $validated),
                'link' => $validated['link'],
                'type' => $validated['type'],
                'song_id' => $validated['song_id'] ?? null,
                'album_id' => $validated['album_id'] ?? null,
                'artist_id' => $validated['artist_id'] ?? null,
                'event_id' => $validated['event_id'] ?? null,
                'playlist_id' => $validated['playlist_id'] ?? null,
                'is_active' => $request->boolean('is_active', true),
                'sort_order' => $validated['sort_order'] ?? $this->nextSortOrder(),
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'created_by_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Featured item created.',
                'data' => $this->serializeItem($item),
            ], 201);
        }, 'Failed to create featured item.');
    }

    /**
     * PUT /api/admin/featured/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $item = FeaturedContent::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'subtitle' => 'sometimes|required|string|max:500',
                'link' => 'sometimes|required|string|max:1000',
                'type' => 'sometimes|required|in:song,album,artist,playlist,event,custom',
                'image' => 'nullable|image|max:5120',
                'image_url' => 'nullable|string|max:1000',
                'song_id' => 'nullable|exists:songs,id',
                'album_id' => 'nullable|exists:albums,id',
                'artist_id' => 'nullable|exists:artists,id',
                'event_id' => 'nullable|exists:events,id',
                'playlist_id' => 'nullable|exists:playlists,id',
                'is_active' => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
            ]);

            $updateData = [
                'title' => $validated['title'] ?? $item->title,
                'subtitle' => $validated['subtitle'] ?? $item->subtitle,
                'link' => $validated['link'] ?? $item->link,
                'type' => $validated['type'] ?? $item->type,
                'song_id' => array_key_exists('song_id', $validated) ? $validated['song_id'] : $item->song_id,
                'album_id' => array_key_exists('album_id', $validated) ? $validated['album_id'] : $item->album_id,
                'artist_id' => array_key_exists('artist_id', $validated) ? $validated['artist_id'] : $item->artist_id,
                'event_id' => array_key_exists('event_id', $validated) ? $validated['event_id'] : $item->event_id,
                'playlist_id' => array_key_exists('playlist_id', $validated) ? $validated['playlist_id'] : $item->playlist_id,
                'sort_order' => $validated['sort_order'] ?? $item->sort_order,
                'starts_at' => array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $item->starts_at,
                'ends_at' => array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $item->ends_at,
            ];

            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $newImagePath = $this->resolveImagePath($request, $validated);
            if ($newImagePath !== null) {
                if ($item->image_path && $item->image_path !== $newImagePath) {
                    StorageHelper::delete($item->image_path);
                }
                $updateData['image_path'] = $newImagePath;
            }

            $item->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Featured item updated.',
                'data' => $this->serializeItem($item->fresh()),
            ]);
        }, 'Failed to update featured item.');
    }

    /**
     * DELETE /api/admin/featured/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $item = FeaturedContent::findOrFail($id);

            if ($item->image_path) {
                StorageHelper::delete($item->image_path);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Featured item deleted.',
            ]);
        }, 'Failed to delete featured item.');
    }

    /**
     * POST /api/admin/featured/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'required|exists:featured_content,id',
                'items.*.sort_order' => 'required|integer|min:0',
            ]);

            DB::transaction(function () use ($validated) {
                foreach ($validated['items'] as $itemData) {
                    FeaturedContent::whereKey($itemData['id'])->update([
                        'sort_order' => $itemData['sort_order'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Featured items reordered.',
            ]);
        }, 'Failed to reorder featured items.');
    }

    /**
     * POST /api/admin/featured/{id}/toggle
     */
    public function toggle(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $item = FeaturedContent::findOrFail($id);
            $item->update(['is_active' => ! $item->is_active]);

            return response()->json([
                'success' => true,
                'message' => $item->is_active ? 'Featured item activated.' : 'Featured item deactivated.',
                'data' => $this->serializeItem($item->fresh()),
            ]);
        }, 'Failed to toggle featured item.');
    }

    private function resolveImagePath(Request $request, array $validated): ?string
    {
        if ($request->hasFile('image')) {
            return StorageHelper::store($request->file('image'), 'featured-content/images');
        }

        if (! empty($validated['image_url'])) {
            return $validated['image_url'];
        }

        return null;
    }

    private function nextSortOrder(): int
    {
        return (int) FeaturedContent::max('sort_order') + 1;
    }

    private function serializeItem(FeaturedContent $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'image_url' => StorageHelper::artworkUrl($item->image_path),
            'link' => $item->link,
            'type' => $item->type,
            'song_id' => $item->song_id,
            'album_id' => $item->album_id,
            'artist_id' => $item->artist_id,
            'event_id' => $item->event_id,
            'playlist_id' => $item->playlist_id,
            'is_active' => (bool) $item->is_active,
            'sort_order' => (int) $item->sort_order,
            'starts_at' => $item->starts_at?->toIso8601String(),
            'ends_at' => $item->ends_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
