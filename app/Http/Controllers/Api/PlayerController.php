<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\UserPlaybackPosition;
use App\Notifications\StreamMilestoneNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /**
     * Update now playing status and record play.
     */
    public function updateNowPlaying(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_id' => 'required|integer|exists:songs,id',
            'is_playing' => 'boolean',
            'volume' => 'integer|min:0|max:100',
            'position' => 'integer|min:0',
        ]);

        $song = Song::findOrFail($validated['song_id']);

        if (! $this->userCanAccessTrack($song, $request->user())) {
            return response()->json([
                'message' => 'Access denied to this track.',
            ], 403);
        }

        return response()->json([
            'data' => [
                'song_id' => $song->id,
                'is_playing' => $validated['is_playing'] ?? false,
            ],
            'message' => 'Now playing updated.',
        ]);
    }

    /**
     * Record a play event.
     *
     * A play only qualifies as a stream when:
     *  - The user listened to ≥90% of the song duration
     *  - The user did NOT forward-seek past content (seeked_forward = true disqualifies)
     */
    public function recordPlay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_id' => 'required|integer|exists:songs,id',
            'duration_played' => 'required|integer|min:0|max:7200',
            'total_duration' => 'nullable|integer|min:1|max:7200',
            'completed' => 'nullable|boolean',
            'seeked_forward' => 'nullable|boolean',
            'timestamp' => 'nullable|integer',
        ]);

        $song = Song::findOrFail($validated['song_id']);

        if (! $this->userCanAccessTrack($song, $request->user())) {
            return response()->json([
                'message' => 'Access denied to this track.',
            ], 403);
        }

        // Prevent duplicate play recordings within short time window
        $recentPlay = PlayHistory::where('user_id', $request->user()->id)
            ->where('song_id', $song->id)
            ->where('played_at', '>=', now()->subMinutes(1))
            ->first();

        if ($recentPlay) {
            return response()->json([
                'message' => 'Play already recorded recently.',
            ], 429);
        }

        $durationPlayed = $validated['duration_played'];
        $totalDuration = $validated['total_duration'] ?? $song->duration_seconds;
        $seekedForward = $validated['seeked_forward'] ?? false;

        // Stream qualifies only when ≥90% of the song was played continuously (no forward-seeking)
        $qualifiedPlay = ! $seekedForward
            && $totalDuration > 0
            && $durationPlayed / $totalDuration >= 0.90;

        if ($qualifiedPlay) {
            $song->increment('play_count');

            // Check for stream milestone and notify artist
            $newPlayCount = $song->fresh()->play_count;
            $milestone = StreamMilestoneNotification::isMilestone($newPlayCount);
            if ($milestone && $song->user) {
                $song->user->notify(new StreamMilestoneNotification($song, $milestone));
            }

            // Process streaming revenue for the artist
            $user = $request->user();
            $isPremiumUser = $user->hasAnyRole(['premium', 'vip', 'artist']) || $user->subscription_status === 'active';

            \App\Jobs\ProcessStreamingRevenue::dispatch(
                $song->id,
                $user->id,
                $song->artist_id,
                $isPremiumUser,
                $user->country ?? null
            )->onQueue('revenue');
        }

        // Record play history
        $this->recordPlayHistory($song, $request->user(), $request, $validated);

        return response()->json([
            'data' => [
                'song_id' => $song->id,
                'play_count' => $song->fresh()->play_count,
                'qualified_play' => $qualifiedPlay,
            ],
            'message' => 'Play recorded.',
        ]);
    }

    /**
     * Save (upsert) the user's last playback position for a song.
     * Pass position_seconds = 0 to clear a saved position (e.g. on song completion).
     */
    public function savePosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_id' => 'required|integer|exists:songs,id',
            'position_seconds' => 'required|integer|min:0|max:7200',
        ]);

        UserPlaybackPosition::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'song_id' => $validated['song_id'],
            ],
            [
                'position_seconds' => $validated['position_seconds'],
            ]
        );

        return response()->json(['message' => 'Position saved.']);
    }

    /**
     * Get the saved resume position for a specific song.
     */
    public function getResumePosition(Request $request, int $songId): JsonResponse
    {
        $position = UserPlaybackPosition::where('user_id', $request->user()->id)
            ->where('song_id', $songId)
            ->first();

        return response()->json([
            'data' => [
                'song_id' => $songId,
                'position_seconds' => $position?->position_seconds ?? 0,
            ],
        ]);
    }

    /**
     * Record detailed play history.
     */
    private function recordPlayHistory($song, $user, $request, $validated = [])
    {
        $durationPlayed = $validated['duration_played'] ?? 0;
        $totalDuration = $validated['total_duration'] ?? $song->duration_seconds;

        PlayHistory::create([
            'user_id' => $user->id,
            'song_id' => $song->id,
            'artist_id' => $song->artist_id,
            'album_id' => $song->album_id,
            'duration_played_seconds' => $durationPlayed,
            'completion_percentage' => $totalDuration > 0 ? round(($durationPlayed / $totalDuration) * 100, 2) : 0,
            'completed' => $validated['completed'] ?? false,
            'skipped' => $durationPlayed < 30,
            'ip_address' => $request->ip(),
            'device_type' => $this->detectDeviceType($request),
            'quality' => $validated['quality'] ?? '128',
            'played_at' => now(),
        ]);

        // Update unique listeners count (only count once per user per song per day)
        $existingPlay = PlayHistory::where('user_id', $user->id)
            ->where('song_id', $song->id)
            ->where('played_at', '>=', now()->subDay())
            ->count();

        if ($existingPlay <= 1) {
            $song->increment('unique_listeners_count');
        }
    }

    /**
     * Check if user can access track.
     */
    private function userCanAccessTrack($song, $user): bool
    {
        if ($song->status === 'published') {
            return true;
        }

        if ($song->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Detect device type from user agent.
     */
    private function detectDeviceType($request)
    {
        $userAgent = $request->userAgent();
        if (preg_match('/mobile|android|iphone/i', $userAgent)) {
            return 'mobile';
        } elseif (preg_match('/tablet|ipad/i', $userAgent)) {
            return 'tablet';
        }

        return 'desktop';
    }
}
