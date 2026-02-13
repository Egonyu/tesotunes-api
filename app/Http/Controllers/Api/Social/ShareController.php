<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Share;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ShareController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $shares = Share::where('user_id', $user->id)
                ->with(['shareable', 'user'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $shares
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch shares',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'shareable_type' => 'required|string',
                'shareable_id' => 'required|integer',
                'message' => 'nullable|string|max:500',
                'platform' => 'nullable|string|in:internal,facebook,twitter,whatsapp,instagram'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $modelClass = 'App\\Models\\' . ucfirst($request->shareable_type);

            if (!class_exists($modelClass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid shareable type'
                ], 400);
            }

            $shareable = $modelClass::findOrFail($request->shareable_id);
            $user = auth()->user();

            $share = Share::createShare(
                $user,
                $shareable,
                $request->message,
                $request->get('platform', 'internal')
            );

            return response()->json([
                'success' => true,
                'message' => 'Content shared successfully',
                'data' => $share->load(['shareable', 'user'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to share content',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Share $share): JsonResponse
    {
        try {
            $share->load(['shareable', 'user']);
            $share->recordView();

            return response()->json([
                'success' => true,
                'data' => $share
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch share',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function view(Share $share): JsonResponse
    {
        try {
            $share->load(['shareable', 'user']);
            $share->recordView();
            $share->recordClick();

            // Redirect to the actual content
            $redirectUrl = null;

            switch (class_basename($share->shareable)) {
                case 'Song':
                    $redirectUrl = route('songs.show', $share->shareable->id);
                    break;
                case 'Album':
                    $redirectUrl = route('albums.show', $share->shareable->id);
                    break;
                case 'Playlist':
                    $redirectUrl = route('playlists.show', $share->shareable->id);
                    break;
                case 'Artist':
                    $redirectUrl = route('artists.show', $share->shareable->id);
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'share' => $share,
                    'redirect_url' => $redirectUrl
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process share view',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}