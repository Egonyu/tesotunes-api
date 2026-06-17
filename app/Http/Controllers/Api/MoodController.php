<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoodController extends Controller
{
    /**
     * GET /api/content/moods
     *
     * List all active moods, ordered by sort_order then name.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $moods = Mood::active()
                ->ordered()
                ->get()
                ->map(fn (Mood $mood) => [
                    'id' => $mood->id,
                    'name' => $mood->name,
                    'slug' => $mood->slug,
                    'description' => $mood->description,
                    'color' => $mood->color,
                    'artwork_url' => $mood->artwork_url,
                    'song_count' => $mood->song_count,
                ]);

            return response()->json([
                'success' => true,
                'data' => $moods,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load moods.',
            ], 500);
        }
    }

    /**
     * GET /api/content/moods/{mood}
     *
     * Show a single mood with its popular songs. The {mood} segment is the
     * slug (the front-end routes by slug); a numeric id is also accepted.
     */
    public function show(Request $request, string $mood): JsonResponse
    {
        try {
            $moodModel = Mood::active()
                ->where('slug', $mood)
                ->when(is_numeric($mood), fn ($query) => $query->orWhere('id', (int) $mood))
                ->firstOrFail();

            $perPage = min((int) $request->get('per_page', 20), 100);

            $songs = $moodModel->songs()
                ->where('status', 'published')
                ->with('artist:id,name,stage_name,slug')
                ->orderByDesc('play_count')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $moodModel->id,
                    'name' => $moodModel->name,
                    'slug' => $moodModel->slug,
                    'description' => $moodModel->description,
                    'color' => $moodModel->color,
                    'artwork_url' => $moodModel->artwork_url,
                    'song_count' => $moodModel->song_count,
                    'songs' => $songs->getCollection()->map(fn ($song) => [
                        'id' => $song->id,
                        'title' => $song->title,
                        'slug' => $song->slug,
                        'duration_seconds' => $song->duration_seconds,
                        'play_count' => $song->play_count,
                        'artwork_url' => $song->artwork_url,
                        'audio_url' => $song->audio_url,
                        'artist' => $song->artist ? [
                            'id' => $song->artist->id,
                            'name' => $song->artist->stage_name ?? $song->artist->name,
                            'slug' => $song->artist->slug,
                        ] : null,
                    ])->values(),
                ],
                'meta' => [
                    'current_page' => $songs->currentPage(),
                    'last_page' => $songs->lastPage(),
                    'per_page' => $songs->perPage(),
                    'total' => $songs->total(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Mood not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load mood.',
            ], 500);
        }
    }
}
