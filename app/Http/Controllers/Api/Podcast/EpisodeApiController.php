<?php

namespace App\Http\Controllers\Api\Podcast;

use App\Http\Controllers\Controller;
use App\Http\Resources\PodcastEpisodeResource;
use App\Models\PodcastEpisode;
use App\Models\Podcast;
use Illuminate\Http\Request;

class EpisodeApiController extends Controller
{
    /**
     * Get episodes for a podcast (paginated).
     *
     * GET /api/podcasts/{podcast:uuid}/episodes
     */
    public function index(Podcast $podcast)
    {
        return PodcastEpisodeResource::collection(
            $podcast->episodes()
                ->where('status', 'published')
                ->latest('created_at')
                ->paginate(20)
        );
    }

    /**
     * Get single episode details.
     *
     * GET /api/episodes/{uuid}
     */
    public function show(string $uuid)
    {
        $episode = PodcastEpisode::where('uuid', $uuid)
            ->with('podcast')
            ->firstOrFail();

        return new PodcastEpisodeResource($episode);
    }

    /**
     * Get episode audio stream URL.
     */
    public function stream(Podcast $podcast, $episode)
    {
        $episode = PodcastEpisode::findOrFail($episode);

        if ($episode->podcast_id !== $podcast->id) {
            abort(404, 'Episode not found in this podcast.');
        }

        if ($episode->is_premium && !auth()->check()) {
            return response()->json(['message' => 'Premium content requires authentication.'], 401);
        }

        return response()->json([
            'data' => [
                'stream_url'       => $episode->getStreamUrl(),
                'duration_seconds' => $episode->duration_seconds,
                'file_size'        => $episode->file_size,
            ],
        ]);
    }
}
