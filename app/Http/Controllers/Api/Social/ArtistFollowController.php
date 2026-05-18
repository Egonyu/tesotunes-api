<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtistFollowController extends Controller
{
    /**
     * POST /api/artists/{artist}/follow
     *
     * Follow an artist.
     */
    public function follow(Request $request, Artist $artist): JsonResponse
    {
        try {
            $user = $request->user();

            // Prevent self-follow
            if ($artist->user_id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot follow yourself.',
                ], 422);
            }

            // Check if already following
            $exists = UserFollow::where('follower_id', $user->id)
                ->where('followable_id', $artist->id)
                ->where('followable_type', Artist::class)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => true,
                    'message' => 'Already following this artist.',
                    'data' => ['is_following' => true],
                ]);
            }

            UserFollow::create([
                'follower_id' => $user->id,
                'followable_id' => $artist->id,
                'followable_type' => Artist::class,
            ]);

            // Increment cached counter
            $artist->increment('followers_count');

            return response()->json([
                'success' => true,
                'message' => 'Artist followed successfully.',
                'data' => [
                    'is_following' => true,
                    'followers_count' => $artist->fresh()->followers_count,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to follow artist.',
            ], 500);
        }
    }

    /**
     * DELETE /api/artists/{artist}/follow
     *
     * Unfollow an artist.
     */
    public function unfollow(Request $request, Artist $artist): JsonResponse
    {
        try {
            $user = $request->user();

            $deleted = UserFollow::where('follower_id', $user->id)
                ->where('followable_id', $artist->id)
                ->where('followable_type', Artist::class)
                ->delete();

            if ($deleted) {
                $artist->decrement('followers_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Artist unfollowed successfully.',
                'data' => [
                    'is_following' => false,
                    'followers_count' => max(0, $artist->fresh()->followers_count),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unfollow artist.',
            ], 500);
        }
    }

    /**
     * GET /api/artists/{artist}/follow/status
     *
     * Check if the authenticated user follows the artist.
     */
    public function status(Request $request, Artist $artist): JsonResponse
    {
        try {
            $user = $request->user();

            $isFollowing = UserFollow::where('follower_id', $user->id)
                ->where('followable_id', $artist->id)
                ->where('followable_type', Artist::class)
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'is_following' => $isFollowing,
                    'followers_count' => $artist->followers_count,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check follow status.',
            ], 500);
        }
    }
}
