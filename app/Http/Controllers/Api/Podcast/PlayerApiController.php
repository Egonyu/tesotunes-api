<?php

namespace App\Http\Controllers\Api\Podcast;

use App\Http\Controllers\Controller;
use App\Http\Resources\PodcastEpisodeResource;
use App\Models\PodcastEpisode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerApiController extends Controller
{
    /**
     * Record a play event for an episode.
     *
     * POST /api/episodes/{episode:uuid}/play
     */
    public function play(Request $request, string $uuid)
    {
        $episode = PodcastEpisode::where('uuid', $uuid)->firstOrFail();

        // Increment listen count
        $episode->increment('listen_count');

        // Record in listening history
        if ($user = $request->user()) {
            DB::table('podcast_listens')->insert([
                'user_id' => $user->id,
                'episode_id' => $episode->id,
                'position' => 0,
                'completed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'stream_url' => $episode->audio_url,
                'episode' => new PodcastEpisodeResource($episode),
            ],
        ]);
    }

    /**
     * Update playback progress.
     *
     * POST /api/episodes/{episode:uuid}/progress
     */
    public function updateProgress(Request $request, string $uuid)
    {
        $request->validate([
            'position' => 'required|integer|min:0',
        ]);

        $episode = PodcastEpisode::where('uuid', $uuid)->firstOrFail();
        $user = $request->user();

        DB::table('podcast_listens')
            ->where('user_id', $user->id)
            ->where('episode_id', $episode->id)
            ->orderByDesc('created_at')
            ->limit(1)
            ->update([
                'position' => $request->integer('position'),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Progress updated.']);
    }

    /**
     * Mark episode as complete.
     *
     * POST /api/episodes/{episode:uuid}/complete
     */
    public function markComplete(Request $request, string $uuid)
    {
        $episode = PodcastEpisode::where('uuid', $uuid)->firstOrFail();
        $user = $request->user();

        DB::table('podcast_listens')
            ->where('user_id', $user->id)
            ->where('episode_id', $episode->id)
            ->orderByDesc('created_at')
            ->limit(1)
            ->update([
                'completed' => true,
                'position' => $episode->duration ?? 0,
                'updated_at' => now(),
            ]);

        // Increment completion count
        $episode->increment('completion_count');

        return response()->json(['message' => 'Episode marked as complete.']);
    }

    /**
     * Get user's listening queue.
     *
     * GET /api/my-listening-queue
     */
    public function listeningQueue(Request $request)
    {
        $user = $request->user();

        $episodes = PodcastEpisode::select('podcast_episodes.*')
            ->join('podcast_listens', 'podcast_episodes.id', '=', 'podcast_listens.episode_id')
            ->where('podcast_listens.user_id', $user->id)
            ->where('podcast_listens.completed', false)
            ->orderByDesc('podcast_listens.updated_at')
            ->limit(20)
            ->get();

        return PodcastEpisodeResource::collection($episodes);
    }

    /**
     * Get recently played episodes.
     *
     * GET /api/my-recent-podcasts
     */
    public function recentlyPlayed(Request $request)
    {
        $user = $request->user();

        $episodes = PodcastEpisode::select('podcast_episodes.*')
            ->join('podcast_listens', 'podcast_episodes.id', '=', 'podcast_listens.episode_id')
            ->where('podcast_listens.user_id', $user->id)
            ->orderByDesc('podcast_listens.updated_at')
            ->distinct()
            ->limit(20)
            ->get();

        return PodcastEpisodeResource::collection($episodes);
    }
}
