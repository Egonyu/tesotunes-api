<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSocialController extends Controller
{
    /**
     * GET /api/users/suggested
     * Get suggested users to follow.
     */
    public function suggested(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->integer('limit', 5);

        // Get IDs the user already follows
        $followingIds = $user
            ? UserFollow::where('follower_id', $user->id)->pluck('following_id')
            : collect();

        $followingIds->push($user?->id); // Exclude self

        $suggested = User::query()
            ->whereNotIn('id', $followingIds)
            ->where('is_active', true)
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username ?? $u->name,
                'avatar_url' => $u->avatar_url ?? $u->profile_photo_url ?? '',
                'is_verified' => (bool) ($u->is_verified ?? false),
                'bio' => $u->bio ?? '',
                'followers_count' => $u->followers_count ?? 0,
                'is_following' => false,
            ]);

        return response()->json([
            'data' => $suggested,
        ]);
    }

    /**
     * POST /api/users/{user}/follow
     * Follow a user.
     */
    public function follow(Request $request, User $user): JsonResponse
    {
        $follower = $request->user();

        if ($follower->id === $user->id) {
            return response()->json(['message' => 'Cannot follow yourself'], 422);
        }

        $exists = UserFollow::where('follower_id', $follower->id)
            ->where('following_id', $user->id)
            ->exists();

        if (! $exists) {
            UserFollow::create([
                'follower_id' => $follower->id,
                'following_id' => $user->id,
                'following_type' => User::class,
                'followed_at' => now(),
            ]);
        }

        return response()->json([
            'data' => ['following' => true],
            'message' => 'Now following ' . $user->name,
        ]);
    }

    /**
     * DELETE /api/users/{user}/follow
     * Unfollow a user.
     */
    public function unfollow(Request $request, User $user): JsonResponse
    {
        $follower = $request->user();

        UserFollow::where('follower_id', $follower->id)
            ->where('following_id', $user->id)
            ->delete();

        return response()->json([
            'data' => ['following' => false],
            'message' => 'Unfollowed ' . $user->name,
        ]);
    }
}
