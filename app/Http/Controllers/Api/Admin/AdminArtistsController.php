<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminArtistsController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get all artists for admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 12), 100);

            $artists = Artist::with('user:id,email,username')
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->when($request->get('search'), function ($q) use ($request) {
                    $search = $request->get('search');
                    $q->where(function ($query) use ($search) {
                        $query->where('stage_name', 'LIKE', '%'.addcslashes($search, '%_').'%')
                            ->orWhereHas('user', function ($uq) use ($search) {
                                $uq->where('email', 'LIKE', '%'.addcslashes($search, '%_').'%')
                                    ->orWhere('username', 'LIKE', '%'.addcslashes($search, '%_').'%');
                            });
                    });
                })
                ->latest()
                ->paginate($perPage);

            $data = $artists->through(function (Artist $artist) {
                return [
                    'id' => $artist->id,
                    'uuid' => $artist->uuid,
                    'name' => $artist->stage_name,
                    'slug' => $artist->slug,
                    'avatar' => $artist->avatar,
                    'avatar_url' => $artist->avatar ? url('storage/'.$artist->avatar) : null,
                    'status' => $artist->status,
                    'is_verified' => $artist->is_verified,
                    'songs_count' => $artist->total_songs_count,
                    'albums_count' => $artist->total_albums_count,
                    'followers_count' => $artist->followers_count,
                    'total_plays' => $artist->total_plays_count,
                    'created_at' => $artist->created_at,
                    'email' => $artist->user?->email,
                    'username' => $artist->user?->username,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'total' => $data->total(),
                    'per_page' => $data->perPage(),
                    'last_page' => $data->lastPage(),
                ],
            ]);
        }, 'Failed to load artists.');
    }

    /**
     * Get artist statistics for admin.
     */
    public function statistics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => Artist::count(),
                    'verified' => Artist::where('is_verified', true)->count(),
                    'pending_verification' => Artist::where('is_verified', false)->where('status', 'active')->count(),
                    'new_this_month' => Artist::whereMonth('created_at', date('m'))
                        ->whereYear('created_at', date('Y'))
                        ->count(),
                ],
            ]);
        }, 'Failed to load artist statistics.');
    }

    /**
     * Get single artist details for admin.
     */
    public function show($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::with(['user:id,email,username,phone,name', 'primaryGenre:id,name'])
                ->findOrFail($id);

            // Get top songs via relationship
            $topSongs = $artist->songs()
                ->select(['id', 'title', 'slug', 'play_count', 'artwork', 'artist_id'])
                ->orderByDesc('play_count')
                ->limit(5)
                ->get()
                ->map(fn (Song $song) => [
                    'id' => $song->id,
                    'title' => $song->title,
                    'slug' => $song->slug,
                    'plays' => $song->play_count ?? 0,
                    'cover_url' => $song->artwork ? url('storage/'.$song->artwork) : null,
                ]);

            // Get recent albums via relationship
            $recentAlbums = $artist->albums()
                ->select(['id', 'title', 'slug', 'artwork', 'release_date', 'album_type', 'artist_id'])
                ->latest()
                ->limit(4)
                ->get()
                ->map(fn (Album $album) => [
                    'id' => $album->id,
                    'title' => $album->title,
                    'slug' => $album->slug,
                    'cover_url' => $album->artwork ? url('storage/'.$album->artwork) : null,
                    'release_date' => $album->release_date,
                    'album_type' => $album->album_type,
                ]);

            // Social links (model casts to array)
            $socialLinks = $artist->social_links ?? [];

            $data = [
                'id' => $artist->id,
                'uuid' => $artist->uuid,
                'user_id' => $artist->user_id,
                'name' => $artist->stage_name,
                'slug' => $artist->slug,
                'bio' => $artist->bio,
                'avatar' => $artist->avatar,
                'avatar_url' => $artist->avatar ? url('storage/'.$artist->avatar) : null,
                'cover_image' => $artist->cover_image,
                'cover_url' => $artist->cover_image ? url('storage/'.$artist->cover_image) : null,
                'profile_url' => $artist->avatar ? url('storage/'.$artist->avatar) : null,
                'status' => $artist->status,
                'is_verified' => $artist->is_verified,
                'is_featured' => $artist->is_trusted,
                'is_trusted' => $artist->is_trusted,
                'verification_status' => $artist->verification_status,
                'verified_at' => $artist->verified_at,
                'website' => $artist->website_url,
                'website_url' => $artist->website_url,
                'primary_genre_id' => $artist->primary_genre_id,
                'total_songs' => $artist->total_songs_count ?? 0,
                'total_albums' => $artist->total_albums_count ?? 0,
                'total_plays' => $artist->total_plays_count ?? 0,
                'followers' => $artist->followers_count ?? 0,
                'total_songs_count' => $artist->total_songs_count,
                'total_albums_count' => $artist->total_albums_count,
                'total_plays_count' => $artist->total_plays_count,
                'followers_count' => $artist->followers_count,
                'earnings_balance' => $artist->earnings_balance,
                'commission_rate' => $artist->commission_rate,
                'can_upload' => $artist->can_upload,
                'auto_publish' => $artist->auto_publish,
                'require_approval' => $artist->require_approval,
                'distribution_suspended' => $artist->distribution_suspended,
                'record_label' => $artist->record_label,
                'career_start_year' => $artist->career_start_year,
                'influences' => $artist->influences,
                'social_links' => $socialLinks,
                'spotify_url' => $socialLinks['spotify'] ?? null,
                'apple_music_url' => $socialLinks['apple_music'] ?? null,
                'youtube_url' => $socialLinks['youtube'] ?? null,
                'instagram_url' => $socialLinks['instagram'] ?? null,
                'twitter_url' => $socialLinks['twitter'] ?? null,
                'facebook_url' => $socialLinks['facebook'] ?? null,
                'tiktok_url' => $socialLinks['tiktok'] ?? null,
                'genres' => $artist->primaryGenre
                    ? [['id' => (string) $artist->primaryGenre->id, 'name' => $artist->primaryGenre->name]]
                    : [],
                'top_songs' => $topSongs,
                'recent_albums' => $recentAlbums,
                'user' => [
                    'id' => $artist->user?->id,
                    'name' => $artist->user?->name ?? '',
                    'email' => $artist->user?->email ?? '',
                    'username' => $artist->user?->username ?? '',
                    'phone' => $artist->user?->phone ?? '',
                ],
                'created_at' => $artist->created_at,
                'updated_at' => $artist->updated_at,
            ];

            return response()->json([
                'success' => true,
                'data' => (object) $data,
            ]);
        }, 'Failed to load artist details.');
    }

    /**
     * Verify an artist.
     */
    public function verify($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);

            $artist->update([
                'is_verified' => true,
                'verification_status' => 'approved',
                'verified_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Artist verified successfully.',
            ]);
        }, 'Failed to verify artist.');
    }

    /**
     * Update artist status.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $request->validate([
                'status' => 'required|in:active,pending,suspended,rejected',
            ]);

            $artist = Artist::findOrFail($id);
            $artist->update(['status' => $request->input('status')]);

            return response()->json([
                'success' => true,
                'message' => 'Artist status updated successfully.',
            ]);
        }, 'Failed to update artist status.');
    }

    /**
     * Delete an artist (soft-delete via SoftDeletes trait).
     */
    public function destroy($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $artist->update(['status' => 'suspended']);
            $artist->delete();

            return response()->json([
                'success' => true,
                'message' => 'Artist deleted successfully.',
            ]);
        }, 'Failed to delete artist.');
    }

    /**
     * Update artist.
     */
    public function update(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $artist = Artist::findOrFail($id);

            $data = [];

            if ($request->has('name')) {
                $data['stage_name'] = $request->input('name');
            }
            if ($request->has('slug')) {
                $data['slug'] = $request->input('slug');
            }
            if ($request->has('bio')) {
                $data['bio'] = $request->input('bio');
            }
            if ($request->has('website')) {
                $data['website_url'] = $request->input('website');
            }
            if ($request->has('status')) {
                $data['status'] = $request->input('status');
            }
            if ($request->has('is_verified')) {
                $data['is_verified'] = (bool) $request->input('is_verified');
            }

            // Social links — merge into existing array (model casts to/from array)
            $keyMap = [
                'spotify_url' => 'spotify',
                'apple_music_url' => 'apple_music',
                'youtube_url' => 'youtube',
                'instagram_url' => 'instagram',
                'twitter_url' => 'twitter',
                'facebook_url' => 'facebook',
                'tiktok_url' => 'tiktok',
            ];
            $existingSocial = $artist->social_links ?? [];
            $socialChanged = false;
            foreach ($keyMap as $inputKey => $socialKey) {
                if ($request->has($inputKey)) {
                    $existingSocial[$socialKey] = $request->input($inputKey);
                    $socialChanged = true;
                }
            }
            if ($socialChanged) {
                $data['social_links'] = $existingSocial;
            }

            // Genre
            $genreIds = $request->input('genre_ids');
            if (is_array($genreIds) && count($genreIds) > 0) {
                $data['primary_genre_id'] = $genreIds[0];
            }

            // File uploads
            if ($request->hasFile('profile_image')) {
                $data['avatar'] = $request->file('profile_image')->store('artists/avatars', 'public');
            }
            if ($request->hasFile('cover_image')) {
                $data['cover_image'] = $request->file('cover_image')->store('artists/covers', 'public');
            }

            $artist->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Artist updated successfully.',
            ]);
        }, 'Failed to update artist.');
    }

    /**
     * Toggle featured status.
     */
    public function toggleFeatured($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $artist->update(['is_trusted' => ! $artist->is_trusted]);

            return response()->json([
                'success' => true,
                'message' => $artist->is_trusted ? 'Artist featured.' : 'Artist unfeatured.',
                'is_featured' => $artist->is_trusted,
            ]);
        }, 'Failed to toggle artist featured status.');
    }

    /**
     * Toggle verify status.
     */
    public function toggleVerify($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $wasVerified = $artist->is_verified;

            $artist->update([
                'is_verified' => ! $wasVerified,
                'verification_status' => $wasVerified ? 'pending' : 'approved',
                'verified_at' => $wasVerified ? null : now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $wasVerified ? 'Artist unverified.' : 'Artist verified.',
            ]);
        }, 'Failed to toggle artist verification.');
    }

    /**
     * Approve a pending artist.
     */
    public function approve($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $artist->update(['status' => 'active']);

            return response()->json([
                'success' => true,
                'message' => 'Artist approved successfully.',
            ]);
        }, 'Failed to approve artist.');
    }

    /**
     * Suspend an artist.
     */
    public function suspend($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $artist->update(['status' => 'suspended']);

            return response()->json([
                'success' => true,
                'message' => 'Artist suspended successfully.',
            ]);
        }, 'Failed to suspend artist.');
    }
}
