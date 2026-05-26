<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Role;
use App\Models\Song;
use App\Notifications\ArtistApplicationNotification;
use App\Services\UserService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminArtistsController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly UserService $userService) {}

    /**
     * Build deterministic cache-busting token for an image field.
     */
    private function imageVersion(Artist $artist, string $field): string
    {
        $path = (string) ($artist->{$field} ?? '');
        $stamp = (string) ($artist->updated_at?->format('Y-m-d H:i:s.u') ?? now()->format('Y-m-d H:i:s.u'));

        return substr(sha1($field.'|'.$path.'|'.$stamp), 0, 16);
    }

    private function isModeratorOnly(): bool
    {
        return (bool) request()->user()?->isModeratorOnly();
    }

    /**
     * PHP 8.4 compatible minimum-dimensions validator.
     * The built-in `dimensions` rule uses getRealPath() which returns '' for tmpfile
     * handles on Windows (PHP 8.4 changed getimagesize('') to throw ValueError
     * instead of returning false, and @ cannot suppress Errors).
     */
    private function minDimensionsRule(int $minWidth, int $minHeight): \Closure
    {
        return function ($attribute, $value, $fail) use ($minWidth, $minHeight) {
            if (! $value instanceof \Illuminate\Http\UploadedFile) {
                return;
            }

            $info = @getimagesize($value->getPathname());

            if ($info !== false && ($info[0] < $minWidth || $info[1] < $minHeight)) {
                $fail("The {$attribute} must be at least {$minWidth}x{$minHeight} pixels.");
            }
        };
    }

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
                $avatarVersion = $this->imageVersion($artist, 'avatar');
                $avatarBase = StorageHelper::url($artist->avatar);

                return [
                    'id' => $artist->id,
                    'uuid' => $artist->uuid,
                    'name' => $artist->stage_name,
                    'slug' => $artist->slug,
                    'avatar' => $artist->avatar,
                    'avatar_url' => $avatarBase ? $avatarBase.'?v='.$avatarVersion : null,
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
     * Build the full artist data payload (reused by show & update).
     */
    private function buildArtistPayload(Artist $artist): array
    {
        $artist->loadMissing(['user:id,email,username,phone,name', 'primaryGenre:id,name']);
        $avatarVersion = $this->imageVersion($artist, 'avatar');
        $coverVersion = $this->imageVersion($artist, 'cover_image');
        $avatarBase = StorageHelper::url($artist->avatar);
        $coverBase = StorageHelper::url($artist->cover_image);

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
                'cover_url' => StorageHelper::url($song->artwork),
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
                'cover_url' => StorageHelper::url($album->artwork),
                'release_date' => $album->release_date,
                'album_type' => $album->album_type,
            ]);

        // Social links (model casts to array)
        $socialLinks = $artist->social_links ?? [];

        return [
            'id' => $artist->id,
            'uuid' => $artist->uuid,
            'user_id' => $artist->user_id,
            'name' => $artist->stage_name,
            'slug' => $artist->slug,
            'bio' => $artist->bio,
            'avatar' => $artist->avatar,
            'avatar_url' => $avatarBase ? $avatarBase.'?v='.$avatarVersion : null,
            'cover_image' => $artist->cover_image,
            'cover_url' => $coverBase ? $coverBase.'?v='.$coverVersion : null,
            'profile_url' => $avatarBase ? $avatarBase.'?v='.$avatarVersion : null,
            'status' => $artist->status,                                    // axis 2
            'is_verified' => $artist->is_verified,                          // axis 3 (featured)
            'is_featured' => $artist->is_trusted,
            'is_trusted' => $artist->is_trusted,
            'kyc_status' => $artist->user?->kyc_status?->value,             // axis 1 (identity)
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
    }

    /**
     * Get single artist details for admin.
     */
    public function show($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::with(['user:id,email,username,phone,name', 'primaryGenre:id,name'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => (object) $this->buildArtistPayload($artist),
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
                'is_verified' => true,                                    // axis 3: featured/blue-check
                'status' => \App\Enums\ArtistStatus::Approved->value,     // axis 2: application approved
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'can_upload' => true,
                'rejection_reason' => null,
            ]);

            $this->syncArtistModerationState($artist, 'approved');

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
                'status' => 'required|in:approved,pending,suspended,rejected',
                'reason' => 'nullable|string|max:1000|required_if:status,rejected',
            ]);

            $artist = Artist::findOrFail($id);
            $status = $request->input('status');
            $artist->update([
                'status' => $status,
                'rejection_reason' => $status === \App\Enums\ArtistStatus::Rejected->value ? $request->input('reason') : null,
                'is_verified' => $status === \App\Enums\ArtistStatus::Approved->value
                    ? true
                    : ($status === \App\Enums\ArtistStatus::Rejected->value ? false : $artist->is_verified),
                'verified_at' => $status === \App\Enums\ArtistStatus::Approved->value
                    ? now()
                    : ($status === \App\Enums\ArtistStatus::Pending->value ? null : $artist->verified_at),
                'verified_by' => $status === 'active' ? auth()->id() : ($status === 'pending' ? null : $artist->verified_by),
                'can_upload' => $status === 'active',
            ]);

            $mappedState = match ($status) {
                'active' => 'approved',
                'rejected' => 'rejected',
                'suspended' => 'suspended',
                default => 'pending',
            };
            $this->syncArtistModerationState($artist->fresh(), $mappedState);

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
            if ($this->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot delete artists.',
                ], 403);
            }

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

            if (config('app.debug')) {
                \Log::info('AdminArtistsController update request debug', [
                    'artist_id' => $id,
                    'content_type' => $request->header('content-type'),
                    'method' => $request->method(),
                    'keys' => array_keys($request->all()),
                    'has_profile_image_key' => $request->has('profile_image'),
                    'has_cover_image_key' => $request->has('cover_image'),
                    'has_profile_image_file' => $request->hasFile('profile_image'),
                    'has_cover_image_file' => $request->hasFile('cover_image'),
                    'profile_image_original_name' => $request->hasFile('profile_image') ? $request->file('profile_image')->getClientOriginalName() : null,
                    'profile_image_mime' => $request->hasFile('profile_image') ? $request->file('profile_image')->getClientMimeType() : null,
                    'profile_image_size' => $request->hasFile('profile_image') ? $request->file('profile_image')->getSize() : null,
                    'cover_image_original_name' => $request->hasFile('cover_image') ? $request->file('cover_image')->getClientOriginalName() : null,
                    'cover_image_mime' => $request->hasFile('cover_image') ? $request->file('cover_image')->getClientMimeType() : null,
                    'cover_image_size' => $request->hasFile('cover_image') ? $request->file('cover_image')->getSize() : null,
                ]);
            }

            // ── Pre-process: strip empty-string URL fields so "nullable|url"
            //    validation doesn't reject them. ConvertEmptyStringsToNull
            //    normally handles this, but multipart/form-data edge cases
            //    can leak through on some environments (Herd, etc.).
            $urlFields = [
                'website', 'spotify_url', 'apple_music_url', 'youtube_url',
                'instagram_url', 'twitter_url', 'facebook_url', 'tiktok_url',
            ];
            foreach ($urlFields as $field) {
                if ($request->has($field) && $request->input($field) === '') {
                    $request->merge([$field => null]);
                }
            }

            // Remove file fields if they're not actual file uploads (empty
            // form submissions can send empty strings for file inputs)
            foreach (['profile_image', 'cover_image'] as $fileField) {
                if ($request->has($fileField) && ! $request->hasFile($fileField)) {
                    $request->request->remove($fileField);
                }
            }

            // Validate — ValidationException now correctly bubbles to 422
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'slug' => 'sometimes|string|max:255|unique:artists,slug,'.$artist->id,
                'bio' => 'sometimes|nullable|string|max:5000',
                'website' => 'sometimes|nullable|url|max:255',
                'status' => 'sometimes|in:active,pending,suspended,rejected',
                'is_verified' => 'sometimes',
                'spotify_url' => 'sometimes|nullable|url|max:255',
                'apple_music_url' => 'sometimes|nullable|url|max:255',
                'youtube_url' => 'sometimes|nullable|url|max:255',
                'instagram_url' => 'sometimes|nullable|url|max:255',
                'twitter_url' => 'sometimes|nullable|url|max:255',
                'facebook_url' => 'sometimes|nullable|url|max:255',
                'tiktok_url' => 'sometimes|nullable|url|max:255',
                'genre_ids' => 'sometimes|array',
                'genre_ids.*' => 'integer|exists:genres,id',
                'profile_image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', $this->minDimensionsRule(50, 50)],
                'cover_image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240', $this->minDimensionsRule(100, 50)],
            ]);

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
                $data['is_verified'] = filter_var($request->input('is_verified'), FILTER_VALIDATE_BOOLEAN);
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
                $data['primary_genre_id'] = (int) $genreIds[0];
            }

            // File uploads via StorageHelper (supports local + DO Spaces)
            if ($request->hasFile('profile_image') && $request->file('profile_image')->isValid()) {
                if ($artist->avatar) {
                    StorageHelper::delete($artist->avatar);
                }
                $data['avatar'] = StorageHelper::store($request->file('profile_image'), 'artists/avatars');
            }
            if ($request->hasFile('cover_image') && $request->file('cover_image')->isValid()) {
                if ($artist->cover_image) {
                    StorageHelper::delete($artist->cover_image);
                }
                $data['cover_image'] = StorageHelper::store($request->file('cover_image'), 'artists/covers');
            }

            if (! empty($data)) {
                $artist->update($data);
            }

            // Refresh the model so data includes newly stored file paths
            $artist->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Artist updated successfully.',
                'data' => (object) $this->buildArtistPayload($artist),
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
                'verified_at' => $wasVerified ? null : now(),
                'verified_by' => $wasVerified ? null : auth()->id(),
                'can_upload' => ! $wasVerified,
                'status' => $wasVerified
                    ? \App\Enums\ArtistStatus::Pending->value
                    : \App\Enums\ArtistStatus::Approved->value,
                'rejection_reason' => null,
            ]);

            $this->syncArtistModerationState($artist->fresh(), $wasVerified ? 'pending' : 'approved');

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
            $artist->update([
                'status' => \App\Enums\ArtistStatus::Approved->value,
                'is_verified' => true,
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'can_upload' => true,
                'rejection_reason' => null,
            ]);
            $this->syncArtistModerationState($artist->fresh(), 'approved');

            return response()->json([
                'success' => true,
                'message' => 'Artist approved successfully.',
            ]);
        }, 'Failed to approve artist.');
    }

    public function reject(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $validated = $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $artist = Artist::findOrFail($id);
            $artist->update([
                'status' => \App\Enums\ArtistStatus::Rejected->value,
                'is_verified' => false,
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'rejection_reason' => $validated['reason'],
                'can_upload' => false,
            ]);

            $this->syncArtistModerationState($artist->fresh(), 'rejected');

            return response()->json([
                'success' => true,
                'message' => 'Artist application rejected successfully.',
            ]);
        }, 'Failed to reject artist.');
    }

    /**
     * Suspend an artist.
     */
    public function suspend($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $artist = Artist::findOrFail($id);
            $artist->update([
                'status' => 'suspended',
                'can_upload' => false,
            ]);
            $this->syncArtistModerationState($artist->fresh(), 'suspended');

            return response()->json([
                'success' => true,
                'message' => 'Artist suspended successfully.',
            ]);
        }, 'Failed to suspend artist.');
    }

    private function syncArtistModerationState(Artist $artist, string $state): void
    {
        $artist->loadMissing('user');

        if (! $artist->user) {
            return;
        }

        $user = $artist->user;
        $artistRole = Role::where('name', Role::ARTIST)->first();
        $userRole = Role::where('name', Role::USER)->first();

        $this->userService->syncArtistReviewState($user, $artist, [
            'application_status' => $state === 'approved' ? 'approved' : $state,
            'verified_at' => $artist->verified_at,
            'verified_by' => $artist->verified_by,
            'rejection_reason' => $artist->rejection_reason,
            'is_artist' => $state === 'approved',
            'artist_profile_active' => $state !== 'suspended',
            'phone_verified_at' => $state === 'approved' ? ($user->phone_verified_at ?? now()) : null,
            'email_verified_at' => $state === 'approved' ? ($user->email_verified_at ?? now()) : null,
        ]);

        if ($state === 'approved') {
            if ($artistRole) {
                $user->roles()->syncWithoutDetaching([
                    $artistRole->id => [
                        'assigned_at' => now(),
                        'assigned_by' => auth()->id(),
                        'is_active' => true,
                    ],
                ]);

                $user->roles()->updateExistingPivot($artistRole->id, [
                    'is_active' => true,
                    'assigned_at' => now(),
                    'assigned_by' => auth()->id(),
                ]);
            }

            if ($userRole) {
                $user->roles()->updateExistingPivot($userRole->id, [
                    'is_active' => false,
                ]);
            }

            $user->forceFill([
                'role' => 'artist',
                'status' => 'active',
            ])->save();

            if (! $artistRole && ! $user->hasRole('artist')) {
                $user->assignRole('artist', auth()->id());
            }

            $user->clearPermissionCache();

            // Identity (KYC) approval flows through KycService — the single writer.
            // No-op when the user has no pending docs.
            $admin = auth()->user();
            if ($admin && $user->kycDocuments()->where('status', 'pending')->exists()) {
                app(\App\Services\Kyc\KycService::class)->markVerified($user, $admin);
            }
        }

        if ($state === 'rejected') {
            $admin = auth()->user();
            if ($admin && $user->kycDocuments()->where('status', 'pending')->exists()) {
                app(\App\Services\Kyc\KycService::class)->markRejected(
                    $user,
                    $admin,
                    $artist->rejection_reason ?? 'Application rejected'
                );
            }
        }

        if (in_array($state, ['rejected', 'suspended'], true)) {
            if ($artistRole) {
                $user->roles()->updateExistingPivot($artistRole->id, [
                    'is_active' => false,
                ]);
            }

            if ($userRole) {
                $user->roles()->syncWithoutDetaching([
                    $userRole->id => [
                        'assigned_at' => now(),
                        'assigned_by' => auth()->id(),
                        'is_active' => true,
                    ],
                ]);

                $user->roles()->updateExistingPivot($userRole->id, [
                    'is_active' => true,
                    'assigned_at' => now(),
                    'assigned_by' => auth()->id(),
                ]);
            }

            $user->forceFill([
                'role' => Role::USER,
                'status' => $state === 'suspended' ? 'suspended' : 'active',
            ])->save();

            $user->clearPermissionCache();
        }

        $notificationState = match ($state) {
            'approved' => ArtistApplicationNotification::APPROVED,
            'rejected' => ArtistApplicationNotification::REJECTED,
            'suspended' => ArtistApplicationNotification::SUSPENDED,
            default => ArtistApplicationNotification::SUBMITTED,
        };

        $user->notify(new ArtistApplicationNotification($notificationState, $artist->rejection_reason));
    }
}
