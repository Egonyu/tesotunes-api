<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Modules\Contributions\Models\SongLyricOptIn;
use App\Modules\Contributions\Services\LyricOptInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Artist-facing per-song opt-in that releases a song's lyrics into the
 * translation task pool. The acting user must own the song (or be an admin).
 */
class LyricOptInController extends Controller
{
    public function __construct(private readonly LyricOptInService $optIns) {}

    /**
     * GET /api/contributions/songs/{song}/optin — current opt-in state.
     */
    public function show(Request $request, int $song): JsonResponse
    {
        $songModel = $this->authorizeSong($request, $song);
        $optIn = SongLyricOptIn::query()->where('song_id', $songModel->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'opted_in' => (bool) $optIn?->isActive(),
                'status' => $optIn?->status,
                'tasks_generated' => (int) ($optIn?->tasks_generated ?? 0),
                'lyric_line_count' => $this->optIns->lyricLines($songModel)->count(),
            ],
        ]);
    }

    /**
     * POST /api/contributions/songs/{song}/optin — opt the song in and generate tasks.
     */
    public function store(Request $request, int $song): JsonResponse
    {
        $songModel = $this->authorizeSong($request, $song);

        if ($this->optIns->lyricLines($songModel)->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'This song has no lyrics to translate. Add lyrics first.',
            ], 422);
        }

        $optIn = $this->optIns->optIn($songModel, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Lyrics opted in for translation.',
            'data' => [
                'status' => $optIn->status,
                'tasks_generated' => $optIn->tasks_generated,
            ],
        ], 201);
    }

    /**
     * DELETE /api/contributions/songs/{song}/optin — withdraw the song.
     */
    public function destroy(Request $request, int $song): JsonResponse
    {
        $songModel = $this->authorizeSong($request, $song);
        $this->optIns->withdraw($songModel);

        return response()->json([
            'success' => true,
            'message' => 'Lyrics withdrawn from the translation pool.',
        ]);
    }

    /**
     * Resolve the song and verify the caller owns it (or is an admin).
     */
    private function authorizeSong(Request $request, int $song): Song
    {
        $songModel = Song::query()->findOrFail($song);
        $user = $request->user();

        $ownsSong = $user->artist && (int) $songModel->artist_id === (int) $user->artist->id;

        if (! $ownsSong && ! $user->hasRole('admin') && ! $user->hasRole('super_admin')) {
            abort(403, 'You can only manage opt-in for your own songs.');
        }

        return $songModel;
    }
}
