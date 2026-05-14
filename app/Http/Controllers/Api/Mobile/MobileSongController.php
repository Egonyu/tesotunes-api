<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use Illuminate\Http\JsonResponse;

class MobileSongController extends Controller
{
    /**
     * GET /mobile/trending/songs
     * Return trending songs for mobile clients.
     *
     * Public endpoint — no auth required. Uses SongResource so all URL
     * generation (artwork, audio, signed streaming) stays consistent
     * with every other songs endpoint.
     */
    public function trending(): JsonResponse
    {
        $songs = Song::with(['artist', 'album', 'primaryGenre'])
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->orderByDesc('play_count')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => SongResource::collection($songs)->resolve(),
        ]);
    }
}
