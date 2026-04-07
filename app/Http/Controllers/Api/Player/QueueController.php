<?php

namespace App\Http\Controllers\Api\Player;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Playlist;
use App\Models\PlayQueue;
use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QueueController extends Controller
{
    public function getQueue(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $queue = PlayQueue::where('user_id', $user->id)
                ->with(['song.artist', 'song.album'])
                ->orderBy('position')
                ->get();

            $currentPlaying = $queue->where('is_current', true)->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'queue' => $queue,
                    'current_playing' => $currentPlaying,
                    'total_duration_seconds' => $queue->sum(fn (PlayQueue $item) => (int) ($item->song?->duration_seconds ?? 0)),
                    'remaining_duration_seconds' => $queue
                        ->where('position', '>', $currentPlaying?->position ?? 0)
                        ->sum(fn (PlayQueue $item) => (int) ($item->song?->duration_seconds ?? 0)),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function addToQueue(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:song,playlist,album',
                'id' => 'required|integer',
                'position' => 'nullable|string|in:next,last',
                'replace' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth()->user();
            $type = $request->type;
            $id = $request->id;
            $position = $request->get('position', 'last');
            $replace = $request->boolean('replace', false);

            // Clear queue if replace is true
            if ($replace) {
                PlayQueue::clearQueue($user);
            }

            $addedCount = 0;

            switch ($type) {
                case 'song':
                    $song = Song::findOrFail($id);
                    if ($position === 'next') {
                        PlayQueue::addNext($user, $song);
                    } else {
                        PlayQueue::addToEnd($user, $song);
                    }
                    $addedCount = 1;
                    break;

                case 'playlist':
                    $playlist = Playlist::findOrFail($id);
                    $songs = $playlist->songs()
                        ->where('status', 'published')
                        ->where('is_streamable', true)
                        ->get();

                    foreach ($songs as $song) {
                        if ($position === 'next') {
                            PlayQueue::addNext($user, $song);
                        } else {
                            PlayQueue::addToEnd($user, $song);
                        }
                    }
                    $addedCount = $songs->count();
                    break;

                case 'album':
                    $album = Album::findOrFail($id);
                    $songs = $album->songs()
                        ->where('status', 'published')
                        ->where('is_streamable', true)
                        ->orderBy('track_number')
                        ->get();

                    foreach ($songs as $song) {
                        if ($position === 'next') {
                            PlayQueue::addNext($user, $song);
                        } else {
                            PlayQueue::addToEnd($user, $song);
                        }
                    }
                    $addedCount = $songs->count();
                    break;
            }

            // Create activity
            $user->activities()->create([
                'type' => 'added_to_queue',
                'activityable_type' => $type === 'song' ? Song::class : ($type === 'playlist' ? Playlist::class : Album::class),
                'activityable_id' => $id,
                'data' => [
                    'type' => $type,
                    'songs_added' => $addedCount,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Added {$addedCount} song(s) to queue",
                'added_count' => $addedCount,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function clearQueue(): JsonResponse
    {
        try {
            $user = auth()->user();
            $clearedCount = PlayQueue::clearQueue($user);

            return response()->json([
                'success' => true,
                'message' => "Cleared {$clearedCount} song(s) from queue",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function shuffleQueue(): JsonResponse
    {
        try {
            $user = auth()->user();
            PlayQueue::shuffleQueue($user);

            return response()->json([
                'success' => true,
                'message' => 'Queue shuffled successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to shuffle queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reorderQueue(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'queue_items' => 'required|array',
                'queue_items.*.id' => 'required|integer|exists:play_queues,id',
                'queue_items.*.position' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth()->user();
            $queueItems = $request->queue_items;

            foreach ($queueItems as $item) {
                PlayQueue::where('id', $item['id'])
                    ->where('user_id', $user->id)
                    ->update(['position' => $item['position']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Queue reordered successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function removeFromQueue(PlayQueue $queueItem): JsonResponse
    {
        try {
            $user = auth()->user();

            if ($queueItem->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to remove this queue item',
                ], 403);
            }

            $queueItem->delete();

            // Reorder remaining queue items
            PlayQueue::where('user_id', $user->id)
                ->where('position', '>', $queueItem->position)
                ->decrement('position');

            return response()->json([
                'success' => true,
                'message' => 'Song removed from queue successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
