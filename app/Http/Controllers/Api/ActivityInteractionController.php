<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Like;
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityInteractionController extends Controller
{
    /**
     * Map route {type} param to Eloquent model class.
     */
    private function resolveModel(string $type): ?string
    {
        return match ($type) {
            'song', 'songs' => Song::class,
            'album', 'albums' => Album::class,
            'artist', 'artists' => Artist::class,
            'playlist', 'playlists' => Playlist::class,
            'activity', 'activities' => Activity::class,
            'event', 'events' => Event::class,
            default => null,
        };
    }

    /**
     * POST /api/like/{type}/{id}
     * Toggle like on any supported entity.
     */
    public function toggleLike(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = $this->resolveModel($type);

        if (! $modelClass) {
            return response()->json(['message' => "Unsupported entity type: {$type}"], 422);
        }

        $entity = $modelClass::find($id);

        if (! $entity) {
            return response()->json(['message' => ucfirst($type).' not found'], 404);
        }

        $liked = Like::toggle($request->user(), $entity);

        return response()->json([
            'data' => [
                'liked' => $liked,
                'type' => $type,
                'id' => $id,
                'like_count' => $entity->fresh()->like_count ?? 0,
            ],
            'message' => $liked ? 'Liked successfully' : 'Unliked successfully',
        ]);
    }

    /**
     * POST /api/bookmark/{type}/{id}
     * Toggle bookmark on any supported entity.
     */
    public function toggleBookmark(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = $this->resolveModel($type);

        if (! $modelClass) {
            return response()->json(['message' => "Unsupported entity type: {$type}"], 422);
        }

        $entity = $modelClass::find($id);

        if (! $entity) {
            return response()->json(['message' => ucfirst($type).' not found'], 404);
        }

        $user = $request->user();

        // Check if bookmark exists
        $bookmark = Like::where('user_id', $user->id)
            ->where('likeable_type', get_class($entity))
            ->where('likeable_id', $entity->id)
            ->where('type', 'bookmark')
            ->first();

        if ($bookmark) {
            $bookmark->delete();
            $bookmarked = false;
        } else {
            Like::create([
                'user_id' => $user->id,
                'likeable_type' => get_class($entity),
                'likeable_id' => $entity->id,
                'type' => 'bookmark',
            ]);
            $bookmarked = true;
        }

        return response()->json([
            'data' => [
                'bookmarked' => $bookmarked,
                'type' => $type,
                'id' => $id,
            ],
            'message' => $bookmarked ? 'Bookmarked successfully' : 'Bookmark removed',
        ]);
    }

    /**
     * POST /api/events/{id}/interest
     * Toggle interest in an event.
     */
    public function toggleEventInterest(Request $request, int $id): JsonResponse
    {
        $event = \App\Models\Event::find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $user = $request->user();
        $isInterested = $user->interestedEvents()->toggle([$event->id]);

        // attached = now interested, detached = no longer interested
        $interested = ! empty($isInterested['attached']);

        return response()->json([
            'data' => [
                'interested' => $interested,
                'event_id' => $id,
            ],
            'message' => $interested ? 'Marked as interested' : 'Interest removed',
        ]);
    }
}
