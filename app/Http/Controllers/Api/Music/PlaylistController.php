<?php

namespace App\Http\Controllers\Api\Music;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Models\Playlist;
use App\Models\PlaylistCollaborator;
use App\Models\PlaylistSong;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFollow as Follow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlaylistController extends Controller
{
    private function normalizeBooleanInput(mixed $value): mixed
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off' => false,
                default => $value,
            };
        }

        return $value;
    }

    public function index(Request $request)
    {
        $query = Playlist::with(['owner'])
            ->where('visibility', 'public')
            ->withCount('songs');

        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        if ($request->has('collaborative')) {
            $query->where('is_collaborative', $request->boolean('collaborative'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.escape_like($request->search).'%');
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        match ($sortBy) {
            'popularity' => $query->orderBy('followers_count', $sortOrder),
            'songs' => $query->orderBy('songs_count', $sortOrder),
            default => $query->orderBy($sortBy, $sortOrder),
        };

        return PlaylistResource::collection($query->paginate($this->getPerPage($request)));
    }

    public function myPlaylists(Request $request)
    {
        $playlists = Playlist::where('user_id', auth()->id())
            ->with(['songs.artist'])
            ->withCount('songs')
            ->orderByDesc('created_at')
            ->paginate($this->getPerPage($request));

        return PlaylistResource::collection($playlists);
    }

    public function featured(Request $request)
    {
        $playlists = Playlist::with(['owner'])
            ->where('is_featured', true)
            ->where('visibility', 'public')
            ->withCount('songs')
            ->orderByDesc('followers_count')
            ->limit($request->integer('limit', 10))
            ->get();

        return PlaylistResource::collection($playlists);
    }

    public function store(Request $request): JsonResponse
    {
        $playlistName = $request->input('title') ?? $request->input('name');
        $normalizedInput = array_merge($request->all(), [
            'playlist_name' => $playlistName,
        ]);
        foreach (['is_public', 'is_collaborative', 'collaboration_requires_approval'] as $booleanField) {
            if ($request->has($booleanField)) {
                $normalizedInput[$booleanField] = $this->normalizeBooleanInput($request->input($booleanField));
            }
        }

        $validator = Validator::make($normalizedInput, [
            'playlist_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_collaborative' => 'boolean',
            'collaboration_requires_approval' => 'boolean',
            'cover_image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $visibility = ($normalizedInput['is_public'] ?? true) ? 'public' : 'private';
        $isCollaborative = (bool) ($normalizedInput['is_collaborative'] ?? false);

        $playlistData = [
            'user_id' => auth()->id(),
            'name' => $playlistName,
            'description' => $request->description,
            'visibility' => $visibility,
            'is_collaborative' => $isCollaborative,
            'collaboration_requires_approval' => $isCollaborative && (bool) ($normalizedInput['collaboration_requires_approval'] ?? false),
        ];

        $artworkFile = $request->file('cover_image');
        if ($artworkFile) {
            $playlistData['artwork'] = StorageHelper::store($artworkFile, 'playlists/artwork');
        }

        if ($isCollaborative) {
            [$playlistData['collaboration_invite_token'], $playlistData['collaboration_invite_expires_at']] = $this->newInviteToken();
        }

        $playlist = Playlist::create($playlistData);

        return (new PlaylistResource($playlist->load('owner')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Playlist $playlist)
    {
        if ($playlist->visibility !== 'public' && (! auth()->check() || ! $playlist->canBeAccessedBy(auth()->user()))) {
            abort(404, 'Playlist not found');
        }

        $playlist->load(['owner', 'songs.artist', 'songs.album'])->loadCount('songs');

        return new PlaylistResource($playlist);
    }

    public function tracks(Request $request, Playlist $playlist)
    {
        if ($playlist->visibility !== 'public' && (! auth()->check() || ! $playlist->canBeAccessedBy(auth()->user()))) {
            abort(404, 'Playlist not found');
        }

        $songs = $playlist->songs()
            ->with(['artist', 'album'])
            ->published()
            ->paginate($this->getPerPage($request));

        return SongResource::collection($songs);
    }

    public function suggestedSongs(Request $request, Playlist $playlist): JsonResponse
    {
        if ($playlist->visibility !== 'public' && (! auth()->check() || ! $playlist->canBeAccessedBy(auth()->user()))) {
            abort(404, 'Playlist not found');
        }

        $existingSongIds = $playlist->songs()->pluck('songs.id');
        $artistIds = $playlist->songs()->pluck('songs.artist_id')->filter()->unique();
        $genreIds = $playlist->songs()
            ->with('genres:id')
            ->get()
            ->flatMap(fn (Song $song) => $song->genres->pluck('id'))
            ->filter()
            ->unique();

        $query = Song::query()
            ->published()
            ->with(['artist', 'album', 'genres'])
            ->whereNotIn('id', $existingSongIds)
            ->when($artistIds->isNotEmpty(), function ($songQuery) use ($artistIds, $genreIds) {
                $songQuery->where(function ($inner) use ($artistIds, $genreIds) {
                    $inner->whereIn('artist_id', $artistIds);

                    if ($genreIds->isNotEmpty()) {
                        $inner->orWhereHas('genres', fn ($genres) => $genres->whereIn('genres.id', $genreIds));
                    }
                });
            }, function ($songQuery) use ($genreIds) {
                if ($genreIds->isNotEmpty()) {
                    $songQuery->whereHas('genres', fn ($genres) => $genres->whereIn('genres.id', $genreIds));
                }
            })
            ->orderByDesc('play_count')
            ->limit($request->integer('limit', 12));

        return response()->json([
            'data' => SongResource::collection($query->get())->resolve(),
        ]);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $normalizedInput = array_merge($request->all(), [
            'is_public' => $request->has('is_public')
                ? $this->normalizeBooleanInput($request->input('is_public'))
                : null,
            'is_collaborative' => $request->has('is_collaborative')
                ? $this->normalizeBooleanInput($request->input('is_collaborative'))
                : null,
            'collaboration_requires_approval' => $request->has('collaboration_requires_approval')
                ? $this->normalizeBooleanInput($request->input('collaboration_requires_approval'))
                : null,
            'remove_artwork' => $request->has('remove_artwork')
                ? $this->normalizeBooleanInput($request->input('remove_artwork'))
                : null,
        ]);

        $validator = Validator::make($normalizedInput, [
            'title' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_collaborative' => 'boolean',
            'collaboration_requires_approval' => 'boolean',
            'remove_artwork' => 'boolean',
            'cover_image' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = Arr::only($normalizedInput, ['description']);

        if ($request->has('title')) {
            $data['name'] = $request->input('title');
        } elseif ($request->has('name')) {
            $data['name'] = $request->input('name');
        }

        if ($request->has('is_public')) {
            $data['visibility'] = ($normalizedInput['is_public'] ?? false) ? 'public' : 'private';
        }

        if ($request->has('is_collaborative')) {
            $data['is_collaborative'] = (bool) ($normalizedInput['is_collaborative'] ?? false);
        }

        if ($request->has('collaboration_requires_approval')) {
            $data['collaboration_requires_approval'] = (bool) ($normalizedInput['collaboration_requires_approval'] ?? false);
        }

        if (array_key_exists('is_collaborative', $data) && ! $data['is_collaborative']) {
            $data['collaboration_requires_approval'] = false;
            $data['collaboration_invite_token'] = null;
            $data['collaboration_invite_expires_at'] = null;
        } elseif (($data['is_collaborative'] ?? $playlist->is_collaborative) && ! $playlist->collaboration_invite_token) {
            [$data['collaboration_invite_token'], $data['collaboration_invite_expires_at']] = $this->newInviteToken();
        }

        if ((bool) ($normalizedInput['remove_artwork'] ?? false)) {
            StorageHelper::delete($playlist->artwork);
            $data['artwork'] = null;
        }

        $artworkFile = $request->file('cover_image');
        if ($artworkFile) {
            if ($playlist->artwork) {
                StorageHelper::delete($playlist->artwork);
            }

            $data['artwork'] = StorageHelper::store($artworkFile, 'playlists/artwork');
        }

        $playlist->update($data);

        return (new PlaylistResource($playlist->fresh()->load('owner')))->response();
    }

    public function destroy(Playlist $playlist): JsonResponse
    {
        if ($playlist->user_id !== auth()->id()) {
            abort(403, 'You are not authorized to delete this playlist');
        }

        $playlist->delete();

        return response()->json(['message' => 'Playlist deleted successfully']);
    }

    public function removeArtwork(Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        StorageHelper::delete($playlist->artwork);
        $playlist->update(['artwork' => null]);

        return response()->json([
            'message' => 'Playlist artwork removed',
            'data' => [
                'artwork_url' => $playlist->fresh()->artwork_url,
            ],
        ]);
    }

    public function addSong(Request $request, Playlist $playlist, Song $song): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        if (PlaylistSong::where('playlist_id', $playlist->id)->where('song_id', $song->id)->exists()) {
            return response()->json(['message' => 'Song already exists in playlist'], 409);
        }

        $playlist->addSong($song, auth()->user());

        return response()->json([
            'message' => 'Song added to playlist',
            'data' => [
                'playlist_id' => $playlist->id,
                'song_id' => $song->id,
                'song_count' => $playlist->fresh()->song_count,
            ],
        ], 201);
    }

    public function addSongFromBody(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $songId = $request->input('track_id') ?? $request->input('song_id');

        if (! $songId) {
            return response()->json(['message' => 'No track_id or song_id provided'], 422);
        }

        $song = Song::findOrFail($songId);

        if (PlaylistSong::where('playlist_id', $playlist->id)->where('song_id', $song->id)->exists()) {
            return response()->json(['message' => 'Song already exists in playlist'], 409);
        }

        $playlist->addSong($song, auth()->user());

        return response()->json([
            'message' => 'Song added to playlist',
            'data' => [
                'playlist_id' => $playlist->id,
                'song_id' => $song->id,
                'song_count' => $playlist->fresh()->song_count,
            ],
        ], 201);
    }

    public function removeSong(Playlist $playlist, Song $song): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $playlist->removeSong($song);

        return response()->json(['message' => 'Song removed from playlist']);
    }

    public function reorderSongs(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $validated = $request->validate([
            'song_ids' => 'required|array|min:1',
            'song_ids.*' => 'integer|distinct',
        ]);

        $existingSongIds = $playlist->playlistSongs()->pluck('song_id')->map(fn ($id) => (int) $id)->values();
        $submittedSongIds = collect($validated['song_ids'])->map(fn ($id) => (int) $id)->values();

        if ($existingSongIds->count() !== $submittedSongIds->count() || $existingSongIds->diff($submittedSongIds)->isNotEmpty()) {
            return response()->json([
                'message' => 'Submitted song order must include each playlist song exactly once',
            ], 422);
        }

        $playlist->reorderSongs($validated['song_ids']);

        return response()->json([
            'message' => 'Playlist reordered',
            'data' => [
                'song_ids' => $validated['song_ids'],
            ],
        ]);
    }

    public function toggleFollow(Playlist $playlist): JsonResponse
    {
        $user = auth()->user();

        if ($playlist->user_id === $user->id) {
            return response()->json(['message' => 'You cannot follow your own playlist'], 400);
        }

        $existing = Follow::where('follower_id', $user->id)
            ->where('followable_type', Playlist::class)
            ->where('followable_id', $playlist->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $playlist->decrement('followers_count');
            $following = false;
        } else {
            Follow::create([
                'follower_id' => $user->id,
                'followable_type' => Playlist::class,
                'followable_id' => $playlist->id,
            ]);
            $playlist->increment('followers_count');
            $following = true;
        }

        return response()->json([
            'data' => [
                'is_following' => $following,
                'follower_count' => $playlist->fresh()->followers_count,
            ],
        ]);
    }

    public function followStatus(Playlist $playlist): JsonResponse
    {
        $user = auth()->user();

        $isFollowing = Follow::query()
            ->where('follower_id', $user->id)
            ->where('followable_type', Playlist::class)
            ->where('followable_id', $playlist->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'is_following' => $isFollowing,
                'followers_count' => (int) $playlist->followers_count,
            ],
        ]);
    }

    public function collaborators(Playlist $playlist): JsonResponse
    {
        $user = auth()->user();

        if (! $playlist->canBeAccessedBy($user)) {
            abort(403, 'You are not authorized to view collaborators for this playlist');
        }

        $playlist->load([
            'owner',
            'activeCollaborators.user',
            'activeCollaborators.invitedBy',
            'pendingCollaborators.user',
            'pendingCollaborators.invitedBy',
        ]);

        $collaborators = collect([
            [
                'id' => 'owner-'.$playlist->user_id,
                'user' => $this->formatUser($playlist->owner),
                'role' => 'owner',
                'status' => PlaylistCollaborator::STATUS_ACCEPTED,
                'added_at' => $playlist->created_at?->toIso8601String(),
                'approved_at' => $playlist->created_at?->toIso8601String(),
                'joined_at' => $playlist->created_at?->toIso8601String(),
                'invited_by' => null,
                'can_edit' => true,
                'can_manage' => true,
            ],
        ])->merge(
            $playlist->activeCollaborators->map(fn (PlaylistCollaborator $collaborator) => $this->formatCollaborator($collaborator))
        );

        if ($playlist->canManageCollaborators($user)) {
            $collaborators = $collaborators->merge(
                $playlist->pendingCollaborators->map(fn (PlaylistCollaborator $collaborator) => $this->formatCollaborator($collaborator))
            );
        }

        return response()->json([
            'data' => $collaborators->values(),
        ]);
    }

    public function addCollaborator(Request $request, Playlist $playlist): JsonResponse
    {
        $actor = auth()->user();

        if (! $playlist->canManageCollaborators($actor)) {
            abort(403, 'Only playlist managers can manage collaborators');
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'nullable|string|in:'.implode(',', PlaylistCollaborator::VALID_ROLES),
        ]);

        $targetUserId = (int) $validated['user_id'];

        if ($targetUserId === (int) $playlist->user_id) {
            return response()->json(['message' => 'The playlist owner is already a collaborator'], 422);
        }

        $role = $validated['role'] ?? PlaylistCollaborator::ROLE_EDITOR;
        $now = now();

        $collaborator = PlaylistCollaborator::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'user_id' => $targetUserId,
            ],
            [
                'role' => $role,
                'status' => PlaylistCollaborator::STATUS_ACCEPTED,
                'invited_by' => $actor->id,
                'approved_at' => $now,
                'joined_at' => $now,
            ]
        );

        $collaborator->load(['user', 'invitedBy']);

        return response()->json([
            'message' => 'Collaborator added',
            'data' => $this->formatCollaborator($collaborator),
        ], 201);
    }

    public function updateCollaboratorRole(Request $request, Playlist $playlist, PlaylistCollaborator $collaborator): JsonResponse
    {
        $user = auth()->user();

        if (! $playlist->canManageCollaborators($user)) {
            abort(403, 'Only playlist managers can update collaborator roles');
        }

        if ($collaborator->playlist_id !== $playlist->id) {
            abort(404, 'Collaborator not found');
        }

        $validated = $request->validate([
            'role' => 'required|string|in:'.implode(',', PlaylistCollaborator::VALID_ROLES),
        ]);

        $collaborator->update([
            'role' => $validated['role'],
        ]);

        $collaborator->load(['user', 'invitedBy']);

        return response()->json([
            'message' => 'Collaborator role updated',
            'data' => $this->formatCollaborator($collaborator),
        ]);
    }

    public function approveCollaborator(Playlist $playlist, PlaylistCollaborator $collaborator): JsonResponse
    {
        $user = auth()->user();

        if (! $playlist->canManageCollaborators($user)) {
            abort(403, 'Only playlist managers can approve collaborators');
        }

        if ($collaborator->playlist_id !== $playlist->id) {
            abort(404, 'Collaborator not found');
        }

        $collaborator->update([
            'status' => PlaylistCollaborator::STATUS_ACCEPTED,
            'approved_at' => now(),
            'joined_at' => $collaborator->joined_at ?? now(),
        ]);

        $collaborator->load(['user', 'invitedBy']);

        return response()->json([
            'message' => 'Collaborator approved',
            'data' => $this->formatCollaborator($collaborator),
        ]);
    }

    public function removeCollaborator(Playlist $playlist, PlaylistCollaborator $collaborator): JsonResponse
    {
        if (! $playlist->canManageCollaborators(auth()->user())) {
            abort(403, 'Only playlist managers can manage collaborators');
        }

        if ($collaborator->playlist_id !== $playlist->id) {
            abort(404, 'Collaborator not found');
        }

        $collaborator->delete();

        return response()->json([
            'message' => 'Collaborator removed',
        ]);
    }

    public function setCollaborative(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canManageCollaborators(auth()->user())) {
            abort(403, 'Only playlist managers can change collaborative mode');
        }

        $validated = $request->validate([
            'is_collaborative' => 'required|boolean',
            'collaboration_requires_approval' => 'nullable|boolean',
        ]);

        $payload = [
            'is_collaborative' => (bool) $validated['is_collaborative'],
            'collaboration_requires_approval' => (bool) ($validated['collaboration_requires_approval'] ?? $playlist->collaboration_requires_approval),
        ];

        if (! $payload['is_collaborative']) {
            $payload['collaboration_requires_approval'] = false;
            $payload['collaboration_invite_token'] = null;
            $payload['collaboration_invite_expires_at'] = null;
        } elseif (! $playlist->collaboration_invite_token || ($playlist->collaboration_invite_expires_at && $playlist->collaboration_invite_expires_at->isPast())) {
            [$payload['collaboration_invite_token'], $payload['collaboration_invite_expires_at']] = $this->newInviteToken();
        }

        $playlist->update($payload);

        return response()->json([
            'message' => $playlist->fresh()->is_collaborative ? 'Collaboration enabled' : 'Collaboration disabled',
            'data' => [
                'is_collaborative' => (bool) $playlist->fresh()->is_collaborative,
                'collaboration_requires_approval' => (bool) $playlist->fresh()->collaboration_requires_approval,
            ],
        ]);
    }

    public function generateInviteLink(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canManageCollaborators(auth()->user())) {
            abort(403, 'Only playlist managers can create invite links');
        }

        if (! $playlist->is_collaborative) {
            return response()->json([
                'message' => 'Enable collaboration before generating an invite link',
            ], 422);
        }

        $validated = $request->validate([
            'expires_in_hours' => 'nullable|integer|min:1|max:168',
        ]);

        [$token, $expiresAt] = $this->newInviteToken($validated['expires_in_hours'] ?? 168);

        $playlist->update([
            'collaboration_invite_token' => $token,
            'collaboration_invite_expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => [
                'invite_token' => $token,
                'invite_url' => url("/playlists/invite/{$token}"),
                'expires_at' => $expiresAt?->toIso8601String(),
                'requires_approval' => (bool) $playlist->collaboration_requires_approval,
            ],
        ]);
    }

    public function invitePreview(string $token): JsonResponse
    {
        $playlist = Playlist::with('owner')
            ->where('collaboration_invite_token', $token)
            ->first();

        if (! $playlist || ! $playlist->is_collaborative || $this->inviteTokenExpired($playlist)) {
            abort(404, 'Invite not found');
        }

        $existingCollaborator = auth()->check()
            ? $playlist->collaborators()->where('user_id', auth()->id())->first()
            : null;

        return response()->json([
            'data' => [
                'playlist' => (new PlaylistResource($playlist))->resolve(),
                'requires_approval' => (bool) $playlist->collaboration_requires_approval,
                'expires_at' => $playlist->collaboration_invite_expires_at?->toIso8601String(),
                'membership' => $existingCollaborator ? [
                    'status' => $existingCollaborator->status,
                    'role' => $existingCollaborator->role,
                ] : null,
            ],
        ]);
    }

    public function joinInvite(Request $request, string $token): JsonResponse
    {
        $playlist = Playlist::where('collaboration_invite_token', $token)->first();
        $user = auth()->user();

        if (! $playlist || ! $playlist->is_collaborative || $this->inviteTokenExpired($playlist)) {
            abort(404, 'Invite not found');
        }

        if ((int) $playlist->user_id === (int) $user->id) {
            return response()->json([
                'message' => 'You already own this playlist',
            ], 422);
        }

        $existing = PlaylistCollaborator::where('playlist_id', $playlist->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->status === PlaylistCollaborator::STATUS_ACCEPTED) {
            return response()->json([
                'message' => 'You are already a collaborator',
                'data' => $this->formatCollaborator($existing->load(['user', 'invitedBy'])),
            ]);
        }

        $status = $playlist->collaboration_requires_approval
            ? PlaylistCollaborator::STATUS_PENDING
            : PlaylistCollaborator::STATUS_ACCEPTED;
        $now = now();

        $collaborator = PlaylistCollaborator::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $existing?->role ?? PlaylistCollaborator::ROLE_EDITOR,
                'status' => $status,
                'invited_by' => $playlist->user_id,
                'joined_at' => $now,
                'approved_at' => $status === PlaylistCollaborator::STATUS_ACCEPTED ? $now : null,
            ]
        );

        $collaborator->load(['user', 'invitedBy']);

        return response()->json([
            'message' => $status === PlaylistCollaborator::STATUS_ACCEPTED
                ? 'You joined the playlist'
                : 'Join request sent for approval',
            'data' => $this->formatCollaborator($collaborator),
        ], $status === PlaylistCollaborator::STATUS_ACCEPTED ? 200 : 202);
    }

    private function formatCollaborator(PlaylistCollaborator $collaborator): array
    {
        return [
            'id' => $collaborator->id,
            'user' => $this->formatUser($collaborator->user),
            'role' => $collaborator->role,
            'status' => $collaborator->status,
            'added_at' => $collaborator->created_at?->toIso8601String(),
            'approved_at' => $collaborator->approved_at?->toIso8601String(),
            'joined_at' => $collaborator->joined_at?->toIso8601String(),
            'invited_by' => $collaborator->invitedBy ? $this->formatUser($collaborator->invitedBy) : null,
            'can_edit' => $collaborator->canEdit(),
            'can_manage' => $collaborator->canManageCollaborators(),
        ];
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => StorageHelper::avatarUrl($user->avatar, $user->name),
        ];
    }

    private function inviteTokenExpired(Playlist $playlist): bool
    {
        return ! $playlist->collaboration_invite_token
            || ($playlist->collaboration_invite_expires_at instanceof Carbon
                && $playlist->collaboration_invite_expires_at->isPast());
    }

    private function newInviteToken(int $expiresInHours = 168): array
    {
        return [
            Str::random(48),
            now()->addHours($expiresInHours),
        ];
    }
}
