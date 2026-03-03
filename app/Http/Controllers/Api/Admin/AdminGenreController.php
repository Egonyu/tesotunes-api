<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Genre;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminGenreController extends Controller
{
    use HandlesApiErrors;

    /**
     * List all genres for admin (includes inactive).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 50), 100);

            $genres = Genre::query()
                ->withCount(['songs' => function ($q) {
                    $q->where('status', 'published');
                }])
                ->when($request->get('search'), function ($q) use ($request) {
                    $search = addcslashes($request->get('search'), '%_');
                    $q->where('name', 'LIKE', '%'.$search.'%');
                })
                ->when($request->has('is_active'), function ($q) use ($request) {
                    $q->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate($perPage);

            $data = $genres->through(function (Genre $genre) {
                return [
                    'id' => $genre->id,
                    'uuid' => $genre->uuid,
                    'name' => $genre->name,
                    'slug' => $genre->slug,
                    'description' => $genre->description,
                    'color' => $genre->color,
                    'icon' => $genre->icon,
                    'emoji' => $genre->emoji ?? null,
                    'is_active' => $genre->is_active,
                    'sort_order' => $genre->sort_order,
                    'songs_count' => $genre->songs_count,
                    'artwork_url' => $genre->artwork_url,
                    'created_at' => $genre->created_at,
                    'updated_at' => $genre->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'total' => $data->total(),
                    'per_page' => $data->perPage(),
                    'last_page' => $data->lastPage(),
                ],
            ]);
        }, 'Failed to load genres.');
    }

    /**
     * Get a single genre.
     */
    public function show($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $genre = Genre::withCount(['songs' => function ($q) {
                $q->where('status', 'published');
            }])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $genre->id,
                    'uuid' => $genre->uuid,
                    'name' => $genre->name,
                    'slug' => $genre->slug,
                    'description' => $genre->description,
                    'color' => $genre->color,
                    'icon' => $genre->icon,
                    'emoji' => $genre->emoji ?? null,
                    'is_active' => $genre->is_active,
                    'sort_order' => $genre->sort_order,
                    'songs_count' => $genre->songs_count,
                    'artwork_url' => $genre->artwork_url,
                    'meta_title' => $genre->meta_title,
                    'meta_description' => $genre->meta_description,
                    'meta_keywords' => $genre->meta_keywords,
                    'created_at' => $genre->created_at,
                    'updated_at' => $genre->updated_at,
                ],
            ]);
        }, 'Failed to load genre.');
    }

    /**
     * Create a new genre.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:genres,name',
                'slug' => 'nullable|string|max:255|unique:genres,slug',
                'description' => 'nullable|string|max:1000',
                'color' => 'nullable|string|max:20',
                'icon' => 'nullable|string|max:50',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer|min:0',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'meta_keywords' => 'nullable|string|max:255',
            ]);

            if (empty($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            $validated['uuid'] = (string) Str::uuid();
            $validated['is_active'] = $validated['is_active'] ?? true;

            $genre = Genre::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Genre created successfully.',
                'data' => [
                    'id' => $genre->id,
                    'name' => $genre->name,
                    'slug' => $genre->slug,
                ],
            ], 201);
        }, 'Failed to create genre.');
    }

    /**
     * Update an existing genre.
     */
    public function update(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $genre = Genre::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255|unique:genres,name,'.$genre->id,
                'slug' => 'nullable|string|max:255|unique:genres,slug,'.$genre->id,
                'description' => 'nullable|string|max:1000',
                'color' => 'nullable|string|max:20',
                'icon' => 'nullable|string|max:50',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer|min:0',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'meta_keywords' => 'nullable|string|max:255',
            ]);

            if (isset($validated['name']) && empty($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            $genre->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Genre updated successfully.',
            ]);
        }, 'Failed to update genre.');
    }

    /**
     * Delete a genre.
     */
    public function destroy($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $genre = Genre::withCount(['songs' => function ($q) {
                $q->where('status', 'published');
            }])->findOrFail($id);

            if ($genre->songs_count > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete genre \"{$genre->name}\" because it has {$genre->songs_count} published song(s). Deactivate it instead.",
                ], 422);
            }

            $genre->delete();

            return response()->json([
                'success' => true,
                'message' => 'Genre deleted successfully.',
            ]);
        }, 'Failed to delete genre.');
    }

    /**
     * Toggle active status.
     */
    public function toggleActive($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $genre = Genre::findOrFail($id);
            $genre->update(['is_active' => ! $genre->is_active]);

            return response()->json([
                'success' => true,
                'message' => $genre->is_active ? 'Genre activated.' : 'Genre deactivated.',
                'is_active' => $genre->is_active,
            ]);
        }, 'Failed to toggle genre status.');
    }
}
