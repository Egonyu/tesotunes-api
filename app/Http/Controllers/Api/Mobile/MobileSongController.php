<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Http\JsonResponse;

class MobileSongController extends Controller
{
    /**
     * Return trending songs for mobile clients.
     *
     * Public endpoint — no auth required. Returns canonical duration fields
     * (duration_seconds + duration_formatted) and never exposes the legacy
     * `duration` alias so mobile clients always get consistent field names.
     */
    public function trending(): JsonResponse
    {
        $songs = Song::where('status', 'published')
            ->where('visibility', 'public')
            ->orderByDesc('play_count')
            ->limit(50)
            ->get(['id', 'title', 'slug', 'duration_seconds', 'play_count', 'like_count', 'artwork', 'artist_id']);

        return response()->json([
            'data' => $songs->map(fn (Song $song) => [
                'id' => $song->id,
                'title' => $song->title,
                'duration_seconds' => (int) $song->duration_seconds,
                'duration_formatted' => $this->formatDuration((int) $song->duration_seconds),
            ]),
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
