<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\UserFollow;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SimpleArtistController extends Controller
{
    public function follow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            // Authorization check
            if (!$user->can('follow', $artist)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to follow this artist'
                ], 403);
            }

            // Check if already following using direct UserFollow model
            $existingFollow = UserFollow::where('follower_id', $user->id)
                ->where('following_id', $artist->user_id)
                ->where('following_type', 'artist')
                ->first();

            if ($existingFollow) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already following this artist'
                ], 400);
            }

            // Create follow relationship
            UserFollow::create([
                'follower_id' => $user->id,
                'following_id' => $artist->user_id,
                'following_type' => 'artist',
            ]);

            // Update artist follower count
            $artist->increment('followers_count');

            return response()->json([
                'success' => true,
                'message' => 'Artist followed successfully',
                'is_following' => true,
                'follower_count' => $artist->fresh()->followers_count
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist follow error: ' . $e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to follow artist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function unfollow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            // Authorization check
            if (!$user->can('unfollow', $artist)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to unfollow this artist'
                ], 403);
            }

            $deleted = UserFollow::where('follower_id', $user->id)
                ->where('following_id', $artist->user_id)
                ->where('following_type', 'artist')
                ->delete();

            if ($deleted) {
                $artist->decrement('followers_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Artist unfollowed successfully',
                'is_following' => false,
                'follower_count' => $artist->fresh()->followers_count
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist unfollow error: ' . $e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unfollow artist',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}