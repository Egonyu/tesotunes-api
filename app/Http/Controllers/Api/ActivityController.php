<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    /**
     * Like an activity.
     */
    public function like(Request $request, $activity): JsonResponse
    {
        $activity = Activity::findOrFail($activity);
        $user = $request->user();

        $existing = Like::where('user_id', $user->id)
            ->where('likeable_type', Activity::class)
            ->where('likeable_id', $activity->id)
            ->where('type', 'like')
            ->first();

        if ($existing) {
            return response()->json(['data' => ['liked' => true], 'message' => 'Already liked.']);
        }

        Like::create([
            'user_id' => $user->id,
            'likeable_type' => Activity::class,
            'likeable_id' => $activity->id,
            'type' => 'like',
        ]);

        return response()->json(['data' => ['liked' => true], 'message' => 'Activity liked.'], 201);
    }

    /**
     * Unlike an activity.
     */
    public function unlike(Request $request, $activity): JsonResponse
    {
        $activity = Activity::findOrFail($activity);
        $user = $request->user();

        $deleted = Like::where('user_id', $user->id)
            ->where('likeable_type', Activity::class)
            ->where('likeable_id', $activity->id)
            ->where('type', 'like')
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Not liked.'], 404);
        }

        return response()->json(['data' => ['liked' => false], 'message' => 'Activity unliked.']);
    }

    /**
     * Get comments for an activity.
     */
    public function getComments(Request $request, $activity): JsonResponse
    {
        $activity = Activity::findOrFail($activity);

        $comments = $activity->comments()
            ->with('user:id,username,profile_photo_path')
            ->latest()
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    /**
     * Add a comment to an activity.
     */
    public function addComment(Request $request, $activity): JsonResponse
    {
        $activity = Activity::findOrFail($activity);

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $comment = $activity->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        $comment->load('user:id,username,profile_photo_path');

        return response()->json(['data' => $comment], 201);
    }
}
