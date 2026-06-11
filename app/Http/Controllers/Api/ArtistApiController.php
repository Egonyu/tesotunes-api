<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\MediaUploadSession;
use App\Models\Payment;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Notifications\AdminSongPendingNotification;
use App\Notifications\SongModerationNotification;
use App\Services\Audio\AudioMetadataService;
use App\Services\NotificationRoutingService;
use App\Services\PayoutService;
use App\Services\Revenue\StreamingRateService;
use App\Services\SongSlugService;
use App\Services\Uploads\SongMultipartUploadService;
use Aws\S3\PostObjectV4;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelAwsS3V3Adapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ArtistApiController extends Controller
{
    private static ?array $songTableColumns = null;

    public function __construct(
        private readonly NotificationRoutingService $notificationRoutingService,
        private readonly SongMultipartUploadService $songMultipartUploadService,
        private readonly AudioMetadataService $audioMetadataService
    ) {}

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Get the configured storage disk for uploads.
     * Falls back to 'public' if default is 'local' (private).
     */
    private function uploadDisk()
    {
        $disk = config('filesystems.default', 'local');
        $diskName = $disk === 'local' ? 'public' : $disk;

        return Storage::disk($diskName);
    }

    /**
     * Get the upload disk name.
     */
    private function uploadDiskName(): string
    {
        $disk = config('filesystems.default', 'local');

        return $disk === 'local' ? 'public' : $disk;
    }

    /**
     * Get the authenticated user's artist record.
     */
    private function artist(Request $request): ?Artist
    {
        $user = $request->user();
        $artist = Artist::where('user_id', $user->id)->first();

        if ($artist) {
            return $artist;
        }

        if (! $this->shouldEnsureArtistProfile($user)) {
            return null;
        }

        return DB::transaction(function () use ($user) {
            $existingArtist = Artist::where('user_id', $user->id)->lockForUpdate()->first();

            if ($existingArtist) {
                return $existingArtist;
            }

            if (! $user->is_artist) {
                $user->forceFill(['is_artist' => true])->save();
            }

            return Artist::create([
                'user_id' => $user->id,
                'stage_name' => $this->defaultArtistStageName($user),
                'slug' => $this->generateUniqueArtistSlug(
                    $this->defaultArtistStageName($user),
                    $user->id
                ),
                'bio' => $user->bio,
                'status' => \App\Enums\ArtistStatus::Approved->value,
                'is_verified' => (bool) $user->is_verified,
                'can_upload' => true,
            ]);
        });
    }

    /**
     * Require the authenticated user to have an artist profile.
     */
    private function requireArtist(Request $request): Artist|JsonResponse
    {
        $artist = $this->artist($request);

        if (! $artist) {
            return response()->json([
                'message' => 'You do not have an artist account.',
            ], 403);
        }

        return $artist;
    }

    private function persistableSongAttributes(array $attributes): array
    {
        $columns = self::$songTableColumns ??= array_flip(Schema::getColumnListing((new Song)->getTable()));

        return array_intersect_key($attributes, $columns);
    }

    private function shouldEnsureArtistProfile(User $user): bool
    {
        return (bool) $user->is_artist || $user->hasRole('artist');
    }

    private function defaultArtistStageName(User $user): string
    {
        return $user->stage_name
            ?? $user->display_name
            ?? $user->name
            ?? $user->username
            ?? 'Artist';
    }

    private function generateUniqueArtistSlug(string $name, int $userId): string
    {
        $baseSlug = Str::slug($name);
        if ($baseSlug === '') {
            $baseSlug = 'artist-'.$userId;
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (Artist::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    // ========================================================================
    // Dashboard
    // ========================================================================

    /**
     * GET /api/artist/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        // Refresh stats if stale
        if (! $artist->stats_last_updated_at || $artist->stats_last_updated_at->isBefore(now()->subHour())) {
            $artist->refreshCachedStats();
            $artist->refresh();
        }

        $recentSongs = Song::where('artist_id', $artist->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artwork' => StorageHelper::url($song->artwork),
                'plays' => (int) ($song->play_count ?? 0),
                'downloads' => (int) ($song->download_count ?? 0),
                'trend' => 0,
                'status' => $song->status ?? 'draft',
                'released' => $song->release_date ?? $song->created_at->toDateString(),
            ]);

        $stats = [
            ['label' => 'Total Plays', 'value' => number_format($artist->total_plays_count ?? 0), 'change' => 0, 'period' => 'all time'],
            ['label' => 'Total Songs', 'value' => (string) ($artist->total_songs_count ?? 0), 'change' => 0, 'period' => 'all time'],
            ['label' => 'Followers', 'value' => number_format($artist->followers_count ?? 0), 'change' => 0, 'period' => 'all time'],
            ['label' => 'Revenue', 'value' => 'UGX '.number_format($artist->total_revenue ?? 0), 'change' => 0, 'period' => 'all time'],
        ];

        // Build chart_data: plays per day over last 30 days
        $songIds = Song::where('artist_id', $artist->id)->pluck('id');
        $chartData = [];
        if ($songIds->isNotEmpty()) {
            $playsPerDay = PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays(30)->startOfDay())
                ->selectRaw('DATE(played_at) as date, COUNT(*) as plays')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('plays', 'date');

            for ($i = 29; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $chartData[] = [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('M j'),
                    'plays' => (int) ($playsPerDay[$date] ?? 0),
                ];
            }
        } else {
            for ($i = 29; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $chartData[] = [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('M j'),
                    'plays' => 0,
                ];
            }
        }

        // Build pending_actions from real data
        $pendingActions = [];
        $pendingSongs = Song::where('artist_id', $artist->id)
            ->whereIn('status', ['pending', 'pending_review'])
            ->count();
        if ($pendingSongs > 0) {
            $pendingActions[] = [
                'type' => 'pending_review',
                'label' => $pendingSongs.' song(s) pending review',
                'count' => $pendingSongs,
                'action' => '/artist/songs?status=pending',
            ];
        }
        $draftSongs = Song::where('artist_id', $artist->id)->where('status', 'draft')->count();
        if ($draftSongs > 0) {
            $pendingActions[] = [
                'type' => 'draft',
                'label' => $draftSongs.' draft song(s)',
                'count' => $draftSongs,
                'action' => '/artist/songs?status=draft',
            ];
        }

        return response()->json([
            'data' => [
                'artist' => [
                    'id' => $artist->id,
                    'name' => $artist->stage_name,
                    'avatar' => StorageHelper::url($artist->avatar),
                    'is_verified' => (bool) $artist->is_verified,
                ],
                'stats' => $stats,
                'recent_songs' => $recentSongs,
                'pending_actions' => $pendingActions,
                'chart_data' => $chartData,
            ],
        ]);
    }

    // ========================================================================
    // Songs CRUD
    // ========================================================================

    /**
     * GET /api/artist/songs
     */
    public function songs(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $perPage = min((int) $request->get('per_page', 20), 100);

        $query = Song::where('artist_id', $artist->id)
            ->with(['album', 'primaryGenre']);

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        // Sort
        $sort = $request->get('sort', 'created_at');
        $order = $request->get('order', 'desc');
        $query->orderBy($sort, $order);

        $songs = $query->paginate($perPage);

        // Status counts
        $statusCounts = [
            'total' => Song::where('artist_id', $artist->id)->count(),
            'published' => Song::where('artist_id', $artist->id)->where('status', 'published')->count(),
            'pending' => Song::where('artist_id', $artist->id)->whereIn('status', ['pending', 'pending_review'])->count(),
            'draft' => Song::where('artist_id', $artist->id)->where('status', 'draft')->count(),
        ];

        return response()->json([
            'data' => $songs->map(fn ($song) => [
                'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                'duration_formatted' => $this->formatDuration((int) ($song->duration_seconds ?? 0)),
                'id' => $song->id,
                'title' => $song->title,
                'slug' => $song->slug,
                'artwork_url' => StorageHelper::url($song->artwork),
                'audio_url' => StorageHelper::streamingUrl($song->audio_file_320, $song->audio_file_128, $song->audio_file_original),
                'stream_url' => StorageHelper::streamingUrl($song->audio_file_320, $song->audio_file_128, $song->audio_file_original),
                'preview_url' => StorageHelper::temporaryUrl($song->audio_file_preview),
                'artist' => [
                    'id' => $artist->id,
                    'name' => $artist->stage_name ?? $artist->name,
                    'slug' => $artist->slug,
                ],
                'album' => $song->album ? $song->album->title : null,
                'plays' => (int) ($song->play_count ?? 0),
                'downloads' => (int) ($song->download_count ?? 0),
                'status' => $song->status ?? 'draft',
                'release_date' => $song->release_date ?? $song->created_at->toDateString(),
                'created_at' => optional($song->created_at)?->toIso8601String(),
            ]),
            'pagination' => [
                'current_page' => $songs->currentPage(),
                'last_page' => $songs->lastPage(),
                'per_page' => $songs->perPage(),
                'total' => $songs->total(),
            ],
            'status_counts' => $statusCounts,
        ]);
    }

    /**
     * GET /api/artist/songs/{id}
     */
    public function showSong(Request $request, int $id): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $song = Song::where('artist_id', $artist->id)
            ->with(['album', 'primaryGenre', 'genres'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                'duration_formatted' => $this->formatDuration((int) ($song->duration_seconds ?? 0)),
                'id' => $song->id,
                'title' => $song->title,
                'slug' => $song->slug,
                'artwork_url' => StorageHelper::url($song->artwork),
                'audio_url' => StorageHelper::streamingUrl($song->audio_file_320, $song->audio_file_128, $song->audio_file_original),
                'stream_url' => StorageHelper::streamingUrl($song->audio_file_320, $song->audio_file_128, $song->audio_file_original),
                'preview_url' => StorageHelper::temporaryUrl($song->audio_file_preview),
                'artist' => [
                    'id' => $artist->id,
                    'name' => $artist->stage_name ?? $artist->name,
                    'slug' => $artist->slug,
                ],
                'album' => $song->album ? $song->album->title : null,
                'album_id' => $song->album_id,
                'plays' => (int) ($song->play_count ?? 0),
                'downloads' => (int) ($song->download_count ?? 0),
                'status' => $song->status ?? 'draft',
                'release_date' => $song->release_date ?? $song->created_at->toDateString(),
                'lyrics' => $song->lyrics,
                'description' => $song->description,
                'is_explicit' => (bool) $song->is_explicit,
                'genre' => $song->primaryGenre ? $song->primaryGenre->name : null,
                'genre_id' => $song->primary_genre_id,
                'primary_genre_id' => $song->primary_genre_id,
                'price' => $song->price,
                'is_free' => (bool) $song->is_free,
                'is_downloadable' => (bool) $song->is_downloadable,
                'isrc' => $song->isrc,
                'isrc_assignment' => $song->getIsrcAssignmentSummary(),
                'featured_artists' => is_array($song->featured_artists)
                    ? implode(', ', array_filter($song->featured_artists))
                    : $song->featured_artists,
                'composer' => $song->composer,
                'producer' => $song->producer,
                'created_at' => optional($song->created_at)?->toIso8601String(),
                'updated_at' => optional($song->updated_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/artist/songs/upload-target
     */
    public function createSongUploadTarget(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        if (! $artist->can_upload && ! $request->user()->canUpload()) {
            return response()->json([
                'message' => 'You are not allowed to upload songs at this time.',
            ], 403);
        }

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(['audio', 'cover'])],
            'filename' => 'required|string|max:255',
            'content_type' => 'nullable|string|max:255',
            'size_bytes' => 'required|integer|min:1',
        ]);

        $kind = $validated['kind'];
        $maxBytes = $kind === 'audio' ? $this->maxSongAudioBytes() : $this->maxSongArtworkBytes();
        $allowedExtensions = $this->allowedDirectUploadExtensions($kind);

        if ((int) $validated['size_bytes'] > $maxBytes) {
            Log::warning('Artist upload target rejected oversized file', [
                'user_id' => $request->user()?->id,
                'kind' => $kind,
                'filename' => $validated['filename'],
                'content_type' => $validated['content_type'] ?? null,
                'declared_size_bytes' => (int) $validated['size_bytes'],
                'max_size_bytes' => $maxBytes,
            ]);

            return response()->json([
                'message' => sprintf(
                    '%s files must be %s or smaller.',
                    Str::headline($kind),
                    $this->formatBytes($maxBytes)
                ),
            ], 422);
        }

        $extension = $this->resolveDirectUploadExtension(
            kind: $kind,
            filename: $validated['filename'],
            contentType: $validated['content_type'] ?? null
        );

        if ($extension === null) {
            Log::warning('Artist upload target rejected unsupported file type', [
                'kind' => $kind,
                'filename' => $validated['filename'],
                'content_type' => $validated['content_type'] ?? null,
            ]);

            return response()->json([
                'message' => sprintf(
                    '%s uploads must use one of: %s.',
                    Str::headline($kind),
                    implode(', ', $allowedExtensions)
                ),
            ], 422);
        }

        $target = $this->buildDirectUploadTarget(
            kind: $kind,
            userId: (int) $request->user()->id,
            extension: $extension,
            contentType: $validated['content_type'] ?? null
        );

        if (! $target) {
            Log::warning('Artist upload target unavailable for storage disk', [
                'user_id' => $request->user()?->id,
                'kind' => $kind,
                'disk' => $this->songUploadDiskName(),
            ]);

            return response()->json([
                'message' => 'Direct cloud uploads are not available for the current storage disk.',
                'code' => 'DIRECT_UPLOAD_UNAVAILABLE',
            ], 422);
        }

        return response()->json([
            'data' => array_merge($target, [
                'kind' => $kind,
                'max_file_size_bytes' => $maxBytes,
            ]),
        ]);
    }

    /**
     * POST /api/artist/songs/upload-sessions
     */
    public function createSongUploadSession(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        if (! $artist->can_upload && ! $request->user()->canUpload()) {
            return response()->json([
                'message' => 'You are not allowed to upload songs at this time.',
            ], 403);
        }

        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'content_type' => 'nullable|string|max:255',
            'size_bytes' => 'required|integer|min:1|max:'.$this->maxSongAudioBytes(),
        ]);

        $extension = $this->resolveDirectUploadExtension(
            kind: 'audio',
            filename: $validated['filename'],
            contentType: $validated['content_type'] ?? null
        );

        if ($extension === null) {
            return response()->json([
                'message' => 'Audio uploads must use one of: '.implode(', ', $this->allowedDirectUploadExtensions('audio')).'.',
            ], 422);
        }

        $session = $this->songMultipartUploadService->createAudioSession(
            user: $request->user(),
            artist: $artist,
            filename: $validated['filename'],
            contentType: $validated['content_type'] ?? null,
            sizeBytes: (int) $validated['size_bytes'],
            extension: $extension,
        );

        return response()->json([
            'data' => [
                'id' => $session->uuid,
                'kind' => $session->kind,
                'part_size_bytes' => $session->part_size_bytes,
                'total_parts' => $session->total_parts,
                'max_file_size_bytes' => $this->maxSongAudioBytes(),
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/artist/songs/upload-sessions/{session}/parts
     */
    public function createSongUploadSessionPartTarget(Request $request, string $session): JsonResponse
    {
        $uploadSession = $this->findOwnedSongUploadSession($request, $session);

        $validated = $request->validate([
            'part_number' => 'required|integer|min:1',
        ]);

        try {
            $target = $this->songMultipartUploadService->buildPartUploadTarget(
                $uploadSession,
                (int) $validated['part_number']
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $target,
        ]);
    }

    /**
     * POST /api/artist/songs/upload-sessions/{session}/parts/{part}/verify
     */
    public function verifySongUploadSessionPart(Request $request, string $session, int $part): JsonResponse
    {
        $uploadSession = $this->findOwnedSongUploadSession($request, $session);

        try {
            $result = $this->songMultipartUploadService->verifyUploadedPart($uploadSession, $part);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $result,
        ], $result['verified'] ? 200 : 409);
    }

    /**
     * POST /api/artist/songs/upload-sessions/{session}/complete
     */
    public function completeSongUploadSession(Request $request, string $session): JsonResponse
    {
        $uploadSession = $this->findOwnedSongUploadSession($request, $session);

        try {
            $completed = $this->songMultipartUploadService->completeSession($uploadSession);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => $uploadSession->uuid,
                'status' => 'completed',
                'key' => $completed['key'],
                'size_bytes' => $completed['size_bytes'],
                'original_filename' => $completed['original_filename'],
            ],
        ]);
    }

    /**
     * POST /api/artist/songs/upload-sessions/{session}/abort
     */
    public function abortSongUploadSession(Request $request, string $session): JsonResponse
    {
        $uploadSession = $this->findOwnedSongUploadSession($request, $session);
        $this->songMultipartUploadService->abortSession($uploadSession);

        return response()->json([
            'message' => 'Upload session aborted.',
        ]);
    }

    /**
     * POST /api/artist/songs — Upload a new song
     */
    public function storeSong(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        // Check upload permissions
        if (! $artist->can_upload && ! $request->user()->canUpload()) {
            return response()->json([
                'message' => 'You are not allowed to upload songs at this time.',
            ], 403);
        }

        // Check monthly upload limit — plan-based limit takes priority over artist-level
        $planLimit = $request->user()->getMonthlyUploadLimit();
        if ($planLimit !== null) {
            // Use plan-based limit
            $currentMonthUploads = $artist->songs()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
            if ($currentMonthUploads >= $planLimit) {
                return response()->json([
                    'message' => "You have reached your monthly upload limit ({$planLimit} songs).",
                ], 429);
            }
        } elseif (! $artist->canUploadThisMonth()) {
            return response()->json([
                'message' => 'You have reached your monthly upload limit ('.$artist->monthly_upload_limit.' songs).',
            ], 429);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'audio' => 'required_without_all:uploaded_audio_key,uploaded_audio_session_id|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:'.$this->maxSongAudioKilobytes(),
            'uploaded_audio_key' => 'required_without_all:audio,uploaded_audio_session_id|string|max:1024',
            'uploaded_audio_session_id' => 'required_without_all:audio,uploaded_audio_key|string|size:36',
            'uploaded_audio_original_name' => 'required_with:uploaded_audio_key|string|max:255',
            'uploaded_audio_mime_type' => 'nullable|string|max:255',
            'uploaded_audio_size_bytes' => 'nullable|integer|min:1|max:'.$this->maxSongAudioBytes(),
            'cover' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'uploaded_cover_key' => 'nullable|string|max:1024',
            'uploaded_cover_original_name' => 'required_with:uploaded_cover_key|string|max:255',
            'uploaded_cover_mime_type' => 'nullable|string|max:255',
            'album_id' => ['nullable', 'integer', Rule::exists('albums', 'id')->where('artist_id', $artist->id)],
            'genre_id' => 'nullable|string',
            'genre_ids' => 'nullable|array',
            'genre_ids.*' => 'exists:genres,id',
            'featured_artists' => 'nullable',
            'lyrics' => 'nullable|string',
            'release_date' => 'nullable|date',
            'price' => 'nullable|numeric|min:0',
            'is_explicit' => 'nullable',
            'description' => 'nullable|string|max:2000',
            'composer' => 'nullable|string|max:255',
            'producer' => 'nullable|string|max:255',
            'is_downloadable' => 'nullable',
            'is_free' => 'nullable',
        ]);

        [$audioPath, $audioExt, $audioSize] = $this->resolveSongAudioUpload($request, $validated);
        $audioMetadata = $this->audioMetadataService->extractFromStoragePath($audioPath, $this->songUploadDiskName());

        // Handle cover image (accept either field name)
        $artworkPath = null;
        $coverUpload = $this->resolveSongArtworkUpload($request, $validated);
        if ($coverUpload !== null) {
            [$artworkPath] = $coverUpload;
            // Ensure artwork is publicly readable. The presigned POST includes
            // acl:public-read for new uploads; this catches any edge case where
            // the object landed as private (e.g. bucket default ACL changed).
            try {
                $this->songUploadDisk()->setVisibility($artworkPath, 'public');
            } catch (\Throwable $e) {
                // Non-fatal — bucket policy may already grant public read.
                Log::info('Could not set artwork visibility to public', ['path' => $artworkPath, 'error' => $e->getMessage()]);
            }
        }

        // Generate slug
        $slug = app(SongSlugService::class)->generateUniqueSlug($validated['title']);

        // Resolve genre_id
        $genreId = null;
        if (! empty($validated['genre_id'])) {
            // Could be a numeric ID or a genre name
            if (is_numeric($validated['genre_id'])) {
                $genreId = (int) $validated['genre_id'];
            } else {
                $genre = \App\Models\Genre::where('name', $validated['genre_id'])
                    ->orWhere('slug', Str::slug($validated['genre_id']))
                    ->first();
                $genreId = $genre?->id;
            }
        } elseif (! empty($validated['genre_ids'])) {
            $genreId = $validated['genre_ids'][0];
        }

        // Determine status based on artist's auto_publish setting
        $status = $artist->auto_publish ? 'published' : 'pending';
        if ($artist->require_approval) {
            $status = 'pending';
        }

        $song = Song::create($this->persistableSongAttributes([
            'title' => $validated['title'],
            'slug' => $slug,
            'artist_id' => $artist->id,
            'user_id' => $request->user()->id,
            'album_id' => $validated['album_id'] ?? null,
            'primary_genre_id' => $genreId,
            'status' => $status,
            'is_explicit' => $request->boolean('is_explicit'),
            'description' => $validated['description'] ?? null,
            'lyrics' => $validated['lyrics'] ?? null,
            'release_date' => $validated['release_date'] ?? null,
            'price' => $validated['price'] ?? 0,
            'is_free' => $request->boolean('is_free', true),
            'is_downloadable' => $request->boolean('is_downloadable', true),
            'is_streamable' => true,
            'composer' => $validated['composer'] ?? null,
            'producer' => $validated['producer'] ?? null,
            'featured_artists' => $validated['featured_artists'] ?? null,
            'audio_file_original' => $audioPath,
            'audio_file_320' => $audioPath,
            'artwork' => $artworkPath,
            'file_format' => $audioMetadata['file_format'] ?? $audioExt,
            'file_size_bytes' => $audioMetadata['file_size_bytes'] ?? $audioSize,
            'bitrate_original' => $audioMetadata['bitrate_original'] ?? null,
            'sample_rate' => $audioMetadata['sample_rate'] ?? null,
            'processing_status' => ['status' => 'completed', 'progress' => 100],
            'visibility' => 'public',
            'duration_seconds' => (int) ($audioMetadata['duration_seconds'] ?? 0),
        ]));

        if (! empty($validated['uploaded_audio_session_id'])) {
            $session = MediaUploadSession::query()
                ->where('uuid', $validated['uploaded_audio_session_id'])
                ->where('user_id', $request->user()->id)
                ->first();

            if ($session) {
                $this->songMultipartUploadService->markConsumed($session);
            }
        }

        // Sync genres
        if (! empty($validated['genre_ids'])) {
            $song->genres()->sync($validated['genre_ids']);
        } elseif ($genreId) {
            $song->genres()->sync([$genreId]);
        }

        // Update artist song count
        $artist->increment('total_songs_count');

        // Clear upload limit cache
        cache()->forget("artist_uploads_{$artist->id}_".now()->format('Y_m'));

        $song->load(['artist', 'album', 'primaryGenre']);

        // Notify the artist that their song is pending review
        if ($status === 'pending') {
            $request->user()->notify(new SongModerationNotification($song, SongModerationNotification::PENDING_REVIEW));

            foreach ($this->notificationRoutingService->moderationRecipients() as $admin) {
                try {
                    $admin->notify(new AdminSongPendingNotification($song, $request->user()));
                } catch (\Throwable $e) {
                    Log::warning('Admin pending-song notification failed', [
                        'song_id' => $song->id,
                        'admin_id' => $admin->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Song uploaded successfully!',
            'data' => [
                'id' => $song->id,
                'title' => $song->title,
                'status' => $song->status,
                'artwork_url' => $artworkPath ? Storage::disk($this->songUploadDiskName())->url($artworkPath) : null,
                'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                'duration_formatted' => $this->formatDuration((int) ($song->duration_seconds ?? 0)),
            ],
        ], 201);
    }

    /**
     * PUT /api/artist/songs/{id}
     */
    public function updateSong(Request $request, int $id): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $song = Song::where('artist_id', $artist->id)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'lyrics' => 'sometimes|nullable|string',
            'description' => 'sometimes|nullable|string|max:2000',
            'release_date' => 'sometimes|nullable|date',
            'is_explicit' => 'sometimes',
            'price' => 'sometimes|nullable|numeric|min:0',
            'album_id' => ['sometimes', 'nullable', 'integer', Rule::exists('albums', 'id')->where('artist_id', $artist->id)],
            'genre_id' => 'sometimes|nullable|string',
            'featured_artists' => 'sometimes|nullable',
            'composer' => 'sometimes|nullable|string|max:255',
            'producer' => 'sometimes|nullable|string|max:255',
            'is_downloadable' => 'sometimes',
            'is_free' => 'sometimes',
            'cover' => 'sometimes|nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'cover' => 'sometimes|nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        $updateData = [];

        foreach ([
            'title',
            'lyrics',
            'description',
            'release_date',
            'price',
            'album_id',
            'featured_artists',
            'composer',
            'producer',
        ] as $field) {
            if ($request->exists($field)) {
                $updateData[$field] = $validated[$field] ?? null;
            }
        }

        if ($request->exists('is_explicit')) {
            $updateData['is_explicit'] = $request->boolean('is_explicit');
        }

        if ($request->exists('is_downloadable')) {
            $updateData['is_downloadable'] = $request->boolean('is_downloadable');
        }

        if ($request->exists('is_free')) {
            $updateData['is_free'] = $request->boolean('is_free');
        }

        if ($request->exists('genre_id')) {
            $genreId = null;
            if (! empty($validated['genre_id'])) {
                if (is_numeric($validated['genre_id'])) {
                    $genreId = (int) $validated['genre_id'];
                } else {
                    $genre = \App\Models\Genre::where('name', $validated['genre_id'])
                        ->orWhere('slug', Str::slug($validated['genre_id']))
                        ->first();
                    $genreId = $genre?->id;
                }
            }

            $updateData['primary_genre_id'] = $genreId;
        }

        $coverFile = $request->file('cover');
        if ($coverFile && $coverFile->isValid()) {
            $coverFileName = Str::uuid().'.'.$coverFile->getClientOriginalExtension();

            if ($song->artwork) {
                StorageHelper::delete($song->artwork);
            }

            $updateData['artwork'] = StorageHelper::store($coverFile, 'songs/artwork', $coverFileName);
        }

        $song->update($updateData);

        if ($request->exists('genre_id')) {
            if (! empty($updateData['primary_genre_id'])) {
                $song->genres()->sync([$updateData['primary_genre_id']]);
            } else {
                $song->genres()->detach();
            }
        }

        return response()->json([
            'message' => 'Song updated successfully.',
            'data' => [
                'id' => $song->id,
                'title' => $song->title,
                'artwork_url' => $song->artwork ? Storage::disk($this->uploadDiskName())->url($song->artwork) : null,
            ],
        ]);
    }

    /**
     * DELETE /api/artist/songs/{id}
     */
    public function deleteSong(Request $request, int $id): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $song = Song::where('artist_id', $artist->id)->findOrFail($id);
        app(SongSlugService::class)->releaseForSoftDelete($song);
        $song->delete();

        $artist->decrement('total_songs_count');

        return response()->json([
            'message' => 'Song deleted successfully.',
        ]);
    }

    /**
     * POST /api/artist/songs/bulk-delete
     */
    public function bulkDeleteSongs(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'song_ids' => 'required|array',
            'song_ids.*' => 'integer',
        ]);

        $songs = Song::where('artist_id', $artist->id)
            ->whereIn('id', $validated['song_ids'])
            ->get();

        foreach ($songs as $song) {
            app(SongSlugService::class)->releaseForSoftDelete($song);
            $song->delete();
        }

        $count = $songs->count();

        $artist->decrement('total_songs_count', $count);

        return response()->json([
            'message' => "$count songs deleted successfully.",
        ]);
    }

    /**
     * POST /api/artist/songs/bulk-status
     */
    public function bulkUpdateSongStatus(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'song_ids' => 'required|array',
            'song_ids.*' => 'integer',
            'status' => 'required|in:draft,pending,published',
        ]);

        Song::where('artist_id', $artist->id)
            ->whereIn('id', $validated['song_ids'])
            ->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Song statuses updated successfully.',
        ]);
    }

    // ========================================================================
    // Albums
    // ========================================================================

    /**
     * GET /api/artist/albums
     */
    public function albums(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $perPage = min((int) $request->get('per_page', 20), 100);

        $albums = Album::where('artist_id', $artist->id)
            ->withCount('songs')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $albums->map(fn ($album) => [
                'id' => $album->id,
                'title' => $album->title,
                'artwork' => $album->artwork ? url('storage/'.$album->artwork) : null,
                'artwork_url' => $album->artwork ? Storage::disk($this->uploadDiskName())->url($album->artwork) : null,
                'songs_count' => $album->songs_count,
            ]),
            'pagination' => [
                'current_page' => $albums->currentPage(),
                'last_page' => $albums->lastPage(),
                'per_page' => $albums->perPage(),
                'total' => $albums->total(),
            ],
        ]);
    }

    /**
     * POST /api/artist/albums
     */
    public function storeAlbum(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'description' => 'nullable|string|max:2000',
            'release_date' => 'nullable|date',
            'type' => 'nullable|in:album,single,ep',
            'genre' => 'nullable|string|max:255',
        ]);

        $artworkPath = null;
        if ($request->hasFile('cover_image')) {
            $coverFile = $request->file('cover_image');
            $coverFileName = Str::uuid().'.'.$coverFile->getClientOriginalExtension();
            $artworkPath = 'albums/artwork/'.$coverFileName;
            $this->uploadDisk()->put($artworkPath, fopen($coverFile->getPathname(), 'r'));
        }

        $slug = Str::slug($validated['title']);
        $originalSlug = $slug;
        $counter = 1;
        while (Album::where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        $genreId = null;
        if (! empty($validated['genre'])) {
            if (is_numeric($validated['genre'])) {
                $genreId = (int) $validated['genre'];
            } else {
                $genre = \App\Models\Genre::where('name', $validated['genre'])
                    ->orWhere('slug', Str::slug($validated['genre']))
                    ->first();
                $genreId = $genre?->id;
            }
        }

        $album = Album::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'artist_id' => $artist->id,
            'artwork' => $artworkPath,
            'description' => $validated['description'] ?? null,
            'release_date' => $validated['release_date'] ?? null,
            'album_type' => $validated['type'] ?? 'album',
            'primary_genre_id' => $genreId,
            'status' => 'published',
        ]);

        $artist->increment('total_albums_count');

        return response()->json([
            'message' => 'Album created successfully',
            'data' => [
                'id' => $album->id,
                'title' => $album->title,
            ],
        ], 201);
    }

    /**
     * GET /api/artist/albums/{id}
     */
    public function showAlbum(Request $request, int $id): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $album = Album::where('artist_id', $artist->id)
            ->with(['songs', 'primaryGenre'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $album->id,
                'title' => $album->title,
                'description' => $album->description,
                'artwork' => $album->artwork ? url('storage/'.$album->artwork) : null,
                'artwork_url' => $album->artwork ? Storage::disk($this->uploadDiskName())->url($album->artwork) : null,
                'type' => $album->album_type ?? 'album',
                'status' => $album->status ?? 'draft',
                'release_date' => $album->release_date?->toDateString(),
                'genre' => $album->primaryGenre?->name,
                'genre_id' => $album->primary_genre_id,
                'songs_count' => $album->songs()->count(),
                'total_plays' => (int) ($album->play_count ?? 0),
                'songs' => $album->songs->map(fn ($song) => [
                    'id' => $song->id,
                    'title' => $song->title,
                    'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                    'play_count' => (int) ($song->play_count ?? 0),
                    'status' => $song->status ?? 'draft',
                ])->values(),
                'created_at' => optional($album->created_at)?->toIso8601String(),
                'updated_at' => optional($album->updated_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * PUT /api/artist/albums/{id}
     */
    public function updateAlbum(Request $request, int $id): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $album = Album::where('artist_id', $artist->id)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'cover_image' => 'sometimes|nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'description' => 'sometimes|nullable|string|max:2000',
            'release_date' => 'sometimes|nullable|date',
            'type' => 'sometimes|nullable|in:album,single,ep',
            'genre' => 'sometimes|nullable|string|max:255',
        ]);

        $updateData = [];
        foreach (['title', 'description', 'release_date'] as $field) {
            if ($request->exists($field)) {
                $updateData[$field] = $validated[$field] ?? null;
            }
        }

        if ($request->exists('type')) {
            $updateData['album_type'] = $validated['type'] ?? 'album';
        }

        if ($request->exists('genre')) {
            $genreId = null;
            if (! empty($validated['genre'])) {
                if (is_numeric($validated['genre'])) {
                    $genreId = (int) $validated['genre'];
                } else {
                    $genre = \App\Models\Genre::where('name', $validated['genre'])
                        ->orWhere('slug', Str::slug($validated['genre']))
                        ->first();
                    $genreId = $genre?->id;
                }
            }

            $updateData['primary_genre_id'] = $genreId;
        }

        if ($request->hasFile('cover_image')) {
            $coverFile = $request->file('cover_image');
            $coverFileName = Str::uuid().'.'.$coverFile->getClientOriginalExtension();
            $artworkPath = 'albums/artwork/'.$coverFileName;
            $this->uploadDisk()->put($artworkPath, fopen($coverFile->getPathname(), 'r'));

            if ($album->artwork) {
                $this->uploadDisk()->delete($album->artwork);
            }

            $updateData['artwork'] = $artworkPath;
        }

        $album->update($updateData);

        return response()->json([
            'message' => 'Album updated successfully.',
            'data' => [
                'id' => $album->id,
                'title' => $album->title,
                'artwork_url' => $album->artwork ? Storage::disk($this->uploadDiskName())->url($album->artwork) : null,
            ],
        ]);
    }

    // ========================================================================
    // Profile
    // ========================================================================

    /**
     * GET /api/artist/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        return response()->json([
            'data' => [
                'id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'bio' => $artist->bio,
                'avatar' => StorageHelper::url($artist->avatar),
                'banner' => StorageHelper::url($artist->cover_image),
                'country' => $request->user()->country ?? null,
                'city' => $request->user()->city ?? null,
                'website_url' => $artist->website_url,
                'social_links' => $artist->social_links,
                'is_verified' => (bool) $artist->is_verified,                       // axis 3: featured badge
                'status' => $artist->status,                                          // axis 2: application status
                'kyc_status' => $request->user()->kyc_status?->value,                 // axis 1: identity
                'payout_phone_number' => $artist->payout_phone_number,
                'can_upload' => (bool) $artist->can_upload,
                'monthly_upload_limit' => $artist->monthly_upload_limit,
                'auto_publish' => (bool) $artist->auto_publish,
                'career_start_year' => $artist->career_start_year,
                'record_label' => $artist->record_label,
                'influences' => $artist->influences ?? [],
            ],
        ]);
    }

    /**
     * PUT /api/artist/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'stage_name' => 'sometimes|string|max:255',
            'bio' => 'nullable|string|max:2000',
            'website_url' => 'nullable|url|max:255',
            'social_links' => 'nullable|array',
            'payout_phone_number' => 'nullable|string|max:20',
            'auto_publish' => 'nullable|boolean',
            'career_start_year' => 'nullable|integer|min:1900|max:2100',
            'record_label' => 'nullable|string|max:255',
            'influences' => 'nullable|array',
            'influences.*' => 'string|max:100',
        ]);

        $artist->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
        ]);
    }

    /**
     * POST /api/artist/profile/avatar
     */
    public function uploadProfileAvatar(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240', function ($attribute, $value, $fail) {
                if (! $value instanceof \Illuminate\Http\UploadedFile) {
                    return;
                }
                $info = @getimagesize($value->getPathname());
                if ($info !== false && ($info[0] < 50 || $info[1] < 50)) {
                    $fail("The {$attribute} must be at least 50x50 pixels.");
                }
            }],
        ]);

        if ($artist->avatar) {
            StorageHelper::delete($artist->avatar);
        }

        $path = StorageHelper::store(
            $validated['avatar'],
            'artists/avatars',
            'artist_avatar_'.Str::uuid().'.'.$validated['avatar']->getClientOriginalExtension()
        );

        $artist->update(['avatar' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Avatar uploaded successfully.',
            'data' => [
                'path' => $path,
                'url' => StorageHelper::url($path),
            ],
        ]);
    }

    /**
     * POST /api/artist/profile/banner
     */
    public function uploadProfileBanner(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'banner' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240|dimensions:min_width=100,min_height=50',
        ]);

        if ($artist->cover_image) {
            StorageHelper::delete($artist->cover_image);
        }

        $path = StorageHelper::store(
            $validated['banner'],
            'artists/banners',
            'artist_banner_'.Str::uuid().'.'.$validated['banner']->getClientOriginalExtension()
        );

        $artist->update(['cover_image' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Banner uploaded successfully.',
            'data' => [
                'path' => $path,
                'url' => StorageHelper::url($path),
            ],
        ]);
    }

    // ========================================================================
    // Earnings
    // ========================================================================

    /**
     * GET /api/artist/earnings
     */
    public function earnings(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $songIds = Song::where('artist_id', $artist->id)->pluck('id');

        // Calculate real earnings from completed payments linked to this artist's songs
        $streamingRevenue = 0;
        $downloadRevenue = 0;
        $tipsRevenue = 0;
        $storeRevenue = 0;
        $thisMonthRevenue = 0;
        $lastMonthRevenue = 0;

        if ($songIds->isNotEmpty()) {
            $streamingRevenue = (float) ArtistRevenue::forArtist($artist->id)
                ->streaming()
                ->confirmed()
                ->sum('net_amount');

            $paymentsByType = Payment::whereIn('song_id', $songIds)
                ->where('status', 'completed')
                ->selectRaw('payment_type, SUM(amount) as total')
                ->groupBy('payment_type')
                ->pluck('total', 'payment_type');

            $downloadRevenue = (float) ($paymentsByType['purchase'] ?? 0);
            $tipsRevenue = (float) ($paymentsByType['tip'] ?? 0);
            $storeRevenue = (float) ($paymentsByType['store_purchase'] ?? 0);

            $thisMonthPayments = Payment::whereIn('song_id', $songIds)
                ->where('status', 'completed')
                ->whereMonth('completed_at', now()->month)
                ->whereYear('completed_at', now()->year)
                ->sum('amount');
            $thisMonthStreamingRevenue = (float) ArtistRevenue::forArtist($artist->id)
                ->streaming()
                ->confirmed()
                ->thisMonth()
                ->sum('net_amount');
            $thisMonthRevenue = (float) $thisMonthPayments + $thisMonthStreamingRevenue;

            $lastMonthPayments = Payment::whereIn('song_id', $songIds)
                ->where('status', 'completed')
                ->whereMonth('completed_at', now()->subMonth()->month)
                ->whereYear('completed_at', now()->subMonth()->year)
                ->sum('amount');
            $lastMonthStreamingRevenue = (float) ArtistRevenue::forArtist($artist->id)
                ->streaming()
                ->confirmed()
                ->lastMonth()
                ->sum('net_amount');
            $lastMonthRevenue = (float) $lastMonthPayments + $lastMonthStreamingRevenue;
        }

        $totalRevenue = $streamingRevenue + $downloadRevenue + $tipsRevenue + $storeRevenue;
        $monthlyChange = $lastMonthRevenue > 0
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : ($thisMonthRevenue > 0 ? 100 : 0);

        // Calculate source percentages
        $sources = [];
        foreach ([
            ['source' => 'Streaming', 'amount' => $streamingRevenue],
            ['source' => 'Downloads', 'amount' => $downloadRevenue],
            ['source' => 'Tips', 'amount' => $tipsRevenue],
            ['source' => 'Store', 'amount' => $storeRevenue],
        ] as $src) {
            $sources[] = [
                'source' => $src['source'],
                'amount' => $src['amount'],
                'percentage' => $totalRevenue > 0 ? round(($src['amount'] / $totalRevenue) * 100, 1) : 0,
            ];
        }

        // Build a mixed transaction feed from completed payments and recorded stream revenue.
        $transactions = [];
        if ($songIds->isNotEmpty()) {
            $recentPayments = Payment::whereIn('song_id', $songIds)
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->limit(20)
                ->get()
                ->map(fn (Payment $payment) => $this->formatPaymentTransaction($payment));

            $recentStreamRevenue = ArtistRevenue::forArtist($artist->id)
                ->streaming()
                ->confirmed()
                ->where('sourceable_type', Song::class)
                ->with('revenueSource')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (ArtistRevenue $revenue) => $this->formatArtistRevenueTransaction($revenue));

            $transactions = $recentPayments
                ->concat($recentStreamRevenue)
                ->sortByDesc('sort_timestamp')
                ->take(20)
                ->values()
                ->map(function (array $transaction) {
                    unset($transaction['sort_timestamp']);

                    return $transaction;
                })
                ->all();
        }

        // Monthly chart data (last 6 months)
        $monthlyChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthPayments = 0;
            $monthPlaysRevenue = 0;
            if ($songIds->isNotEmpty()) {
                $monthPayments = (float) Payment::whereIn('song_id', $songIds)
                    ->where('status', 'completed')
                    ->whereMonth('completed_at', $month->month)
                    ->whereYear('completed_at', $month->year)
                    ->sum('amount');
                $monthPlaysRevenue = (float) ArtistRevenue::forArtist($artist->id)
                    ->streaming()
                    ->confirmed()
                    ->whereMonth('revenue_date', $month->month)
                    ->whereYear('revenue_date', $month->year)
                    ->sum('net_amount');
            }
            $monthlyChart[] = [
                'month' => $month->format('M Y'),
                'label' => $month->format('M'),
                'amount' => $monthPayments + $monthPlaysRevenue,
            ];
        }

        $user = $request->user();
        $walletBalance = (float) ($user->ugx_balance ?? 0);

        $walletTopups = Payment::where('user_id', $user->id)
            ->whereIn('payment_type', ['wallet_topup', 'withdrawal'])
            ->where('status', Payment::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => 'wallet_'.$payment->id,
                'type' => $payment->payment_type === 'wallet_topup' ? 'topup' : 'withdrawal',
                'description' => $payment->description ?? ($payment->payment_type === 'wallet_topup' ? 'Wallet Top-up' : 'Wallet Withdrawal'),
                'amount' => $payment->payment_type === 'wallet_topup' ? (float) $payment->amount : -((float) $payment->amount),
                'status' => $payment->status,
                'date' => optional($payment->completed_at ?? $payment->created_at)->toIso8601String(),
                'sort_timestamp' => ($payment->completed_at ?? $payment->created_at)?->timestamp ?? 0,
            ]);

        $allTransactions = collect($transactions)
            ->concat($walletTopups)
            ->sortByDesc('sort_timestamp')
            ->take(20)
            ->values()
            ->map(function (array $transaction) {
                unset($transaction['sort_timestamp']);

                return $transaction;
            })
            ->all();

        return response()->json([
            'data' => [
                'stats' => [
                    'balance' => (float) ($artist->earnings_balance ?? 0) + $walletBalance,
                    'earnings_balance' => (float) ($artist->earnings_balance ?? 0),
                    'wallet_balance' => $walletBalance,
                    'pending_earnings' => 0,
                    'total_earnings' => $totalRevenue > 0 ? $totalRevenue : (float) ($artist->total_revenue ?? 0),
                    'this_month' => $thisMonthRevenue,
                    'monthly_change' => $monthlyChange,
                ],
                'earnings_sources' => $sources,
                'payout_limits' => [
                    'min_amount' => config('payments.payout.min_amount', 50000),
                    'max_single' => config('payments.payout.max_single', 5000000),
                    'max_daily' => config('payments.payout.max_daily', 10000000),
                    'fee_rates' => config('payments.payout.fees', [
                        'mobile_money' => 1.5,
                        'bank_transfer' => 0.5,
                        'paypal' => 2.0,
                    ]),
                ],
                'streaming_configuration' => app(StreamingRateService::class)->getStreamingConfigurationSummary(),
                'transactions' => $allTransactions,
                'monthly_chart' => $monthlyChart,
            ],
        ]);
    }

    /**
     * GET /api/artist/earnings/payouts
     */
    public function payoutHistory(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $status = $request->query('status');
        $filters = array_filter([
            'status' => $status,
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ]);

        $payouts = app(PayoutService::class)->getArtistPayoutHistory($artist, $filters);

        return response()->json([
            'success' => true,
            'data' => $payouts->map(fn ($p) => [
                'id' => $p->id,
                'transaction_id' => $p->transaction_id,
                'amount' => $p->amount,
                'fee_amount' => $p->fee_amount,
                'net_amount' => $p->net_amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'payout_method' => $p->payout_method,
                'failure_reason' => $p->failure_reason,
                'notes' => $p->notes,
                'approved_at' => $p->approved_at?->toDateTimeString(),
                'processing_started_at' => $p->processing_started_at?->toDateTimeString(),
                'completed_at' => $p->completed_at?->toDateTimeString(),
                'failed_at' => $p->failed_at?->toDateTimeString(),
                'created_at' => $p->created_at->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * POST /api/artist/earnings/withdraw
     */
    public function withdraw(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|in:mtn_momo,airtel_money,bank_transfer,zengapay',
            'phone_number' => 'nullable|string',
        ]);

        $withdrawUser = $request->user();
        $earningsBalance = (float) ($artist->earnings_balance ?? 0);
        $walletBalance = (float) ($withdrawUser->ugx_balance ?? 0);
        $combinedBalance = $earningsBalance + $walletBalance;

        if ($validated['amount'] > $combinedBalance) {
            return response()->json([
                'message' => 'Insufficient balance.',
            ], 422);
        }

        // If the withdrawal exceeds artist earnings, transfer the shortfall from the user's wallet
        $walletContribution = max(0.0, $validated['amount'] - $earningsBalance);
        if ($walletContribution > 0) {
            DB::transaction(function () use ($withdrawUser, $artist, $walletContribution) {
                $withdrawUser->decrement('ugx_balance', $walletContribution);
                $artist->increment('earnings_balance', $walletContribution);
            });
            $artist->refresh();
        }

        try {
            $payoutService = app(PayoutService::class);

            $result = $payoutService->requestPayout(
                artist: $artist,
                amount: $validated['amount'],
                method: $validated['payment_method'],
                payoutData: [
                    'phone_number' => $validated['phone_number'] ?? null,
                ],
                requestedBy: $request->user()
            );

            return response()->json([
                'message' => $result['message'] ?? 'Withdrawal request submitted. You will receive your funds within 24-48 hours.',
                'data' => [
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'amount' => $result['amount'] ?? $validated['amount'],
                    'fee' => $result['fee'] ?? 0,
                    'net_amount' => $result['net_amount'] ?? $validated['amount'],
                    'estimated_processing_time' => $result['estimated_processing_time'] ?? '1-3 business days',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/artist/earnings/songs — per-song earnings breakdown
     */
    public function perSongEarnings(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $perPage = min((int) $request->get('per_page', 20), 100);
        $sort = $request->get('sort', 'total_revenue');

        $songs = Song::where('artist_id', $artist->id)
            ->where('status', 'published')
            ->get();

        $songIds = $songs->pluck('id');

        $paymentsBySong = [];
        $streamRevenueBySong = collect();
        if ($songIds->isNotEmpty()) {
            $paymentsBySong = Payment::whereIn('song_id', $songIds)
                ->where('status', 'completed')
                ->selectRaw('song_id, payment_type, SUM(amount) as total')
                ->groupBy('song_id', 'payment_type')
                ->get()
                ->groupBy('song_id');

            $streamRevenueBySong = ArtistRevenue::forArtist($artist->id)
                ->streaming()
                ->confirmed()
                ->where('sourceable_type', Song::class)
                ->whereIn('sourceable_id', $songIds)
                ->selectRaw('sourceable_id, SUM(net_amount) as total')
                ->groupBy('sourceable_id')
                ->pluck('total', 'sourceable_id');
        }

        $songEarnings = $songs->map(function ($song) use ($paymentsBySong, $streamRevenueBySong) {
            $payments = $paymentsBySong[$song->id] ?? collect();
            $purchaseRevenue = (float) $payments->where('payment_type', 'purchase')->sum('total');
            $tipRevenue = (float) $payments->where('payment_type', 'tip')->sum('total');
            $streamRevenue = (float) ($streamRevenueBySong[$song->id] ?? 0);

            return [
                'song_id' => $song->id,
                'title' => $song->title,
                'artwork_url' => StorageHelper::url($song->artwork),
                'streams_revenue' => $streamRevenue,
                'downloads_revenue' => $purchaseRevenue,
                'tips_revenue' => $tipRevenue,
                'total_revenue' => $streamRevenue + $purchaseRevenue + $tipRevenue,
                'play_count' => (int) ($song->play_count ?? 0),
                'download_count' => (int) ($song->download_count ?? 0),
            ];
        });

        // Sort
        $songEarnings = $sort === 'play_count'
            ? $songEarnings->sortByDesc('play_count')->values()
            : $songEarnings->sortByDesc('total_revenue')->values();

        // Paginate manually
        $page = (int) $request->get('page', 1);
        $total = $songEarnings->count();
        $paginated = $songEarnings->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $paginated,
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * GET /api/artist/royalty-splits — list all royalty splits for this artist
     */
    public function royaltySplits(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        // Check if royalty_splits table exists
        if (! \Illuminate\Support\Facades\Schema::hasTable('royalty_splits')) {
            return response()->json([
                'data' => [],
                'message' => 'Royalty splits feature coming soon.',
            ]);
        }

        $splits = DB::table('royalty_splits')
            ->join('songs', 'royalty_splits.song_id', '=', 'songs.id')
            ->where('songs.artist_id', $artist->id)
            ->select(
                'royalty_splits.*',
                'songs.title as song_title',
                'songs.artwork as song_artwork'
            )
            ->orderByDesc('royalty_splits.created_at')
            ->get()
            ->map(fn ($split) => [
                'id' => $split->id,
                'song_id' => $split->song_id,
                'song_title' => $split->song_title,
                'song_artwork' => $split->song_artwork ? url('storage/'.$split->song_artwork) : null,
                'recipient_id' => $split->recipient_id ?? null,
                'recipient_name' => $split->recipient_name ?? $split->collaborator_name ?? 'Unknown',
                'recipient_email' => $split->recipient_email ?? null,
                'percentage' => (float) ($split->percentage ?? $split->split_percentage ?? 0),
                'applies_to_streaming' => (bool) ($split->applies_to_streaming ?? true),
                'applies_to_downloads' => (bool) ($split->applies_to_downloads ?? true),
                'status' => $split->status ?? 'active',
                'total_earned' => (float) ($split->total_earned ?? 0),
                'pending_payout' => (float) ($split->pending_payout ?? 0),
            ]);

        return response()->json([
            'data' => $splits,
        ]);
    }

    // ========================================================================
    // Analytics
    // ========================================================================

    /**
     * GET /api/artist/analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        $period = (int) $request->get('period', 30);
        $songIds = Song::where('artist_id', $artist->id)->pluck('id');

        $topSongs = Song::where('artist_id', $artist->id)
            ->orderByDesc('play_count')
            ->limit(10)
            ->get()
            ->map(fn ($song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artwork' => StorageHelper::url($song->artwork),
                'play_count' => (int) ($song->play_count ?? 0),
                'download_count' => (int) ($song->download_count ?? 0),
            ]);

        // Build plays_over_time from play_histories
        $playsOverTime = [];
        if ($songIds->isNotEmpty()) {
            $playsPerDay = PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays($period)->startOfDay())
                ->selectRaw('DATE(played_at) as date, COUNT(*) as plays')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('plays', 'date');

            for ($i = $period - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $playsOverTime[] = [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('M j'),
                    'plays' => (int) ($playsPerDay[$date] ?? 0),
                ];
            }
        } else {
            for ($i = $period - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $playsOverTime[] = [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('M j'),
                    'plays' => 0,
                ];
            }
        }

        // Build demographics from play_histories
        $countries = [];
        $devices = [];
        $uniqueListeners = 0;
        $avgListenTime = 0;

        if ($songIds->isNotEmpty()) {
            $countries = PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays($period)->startOfDay())
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->selectRaw('country, COUNT(*) as plays')
                ->groupBy('country')
                ->orderByDesc('plays')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'country' => $row->country,
                    'count' => (int) $row->plays,
                ])
                ->toArray();

            $devices = PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays($period)->startOfDay())
                ->whereNotNull('device_type')
                ->where('device_type', '!=', '')
                ->selectRaw('device_type, COUNT(*) as plays')
                ->groupBy('device_type')
                ->orderByDesc('plays')
                ->get()
                ->map(fn ($row) => [
                    'device_type' => $row->device_type,
                    'count' => (int) $row->plays,
                ])
                ->toArray();

            $uniqueListeners = (int) PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays($period)->startOfDay())
                ->distinct('user_id')
                ->count('user_id');

            $avgListenTime = (int) PlayHistory::whereIn('song_id', $songIds)
                ->where('played_at', '>=', now()->subDays($period)->startOfDay())
                ->whereNotNull('duration_played_seconds')
                ->avg('duration_played_seconds');
        }

        return response()->json([
            'data' => [
                'period' => "{$period} days",
                'plays_over_time' => $playsOverTime,
                'top_songs' => $topSongs,
                'demographics' => [
                    'countries' => $countries,
                    'devices' => $devices,
                ],
                'engagement' => [
                    'total_plays' => (int) ($artist->total_plays_count ?? 0),
                    'unique_listeners' => $uniqueListeners,
                    'avg_listen_time' => $avgListenTime,
                ],
            ],
        ]);
    }

    // ========================================================================
    // Referrals
    // ========================================================================

    /**
     * GET /api/artist/referrals/dashboard
     */
    public function referralsDashboard(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        return response()->json([
            'data' => [
                'stats' => [
                    'total_referrals' => 0,
                    'active_fans' => 0,
                    'total_commission' => 0,
                    'pending_commission' => 0,
                    'conversion_rate' => 0,
                    'this_month_referrals' => 0,
                    'monthly_change' => 0,
                ],
                'link' => [
                    'referral_code' => $artist->slug,
                    'referral_link' => url("/join/{$artist->slug}"),
                    'branded_link' => url("/join/{$artist->slug}"),
                    'qr_code_url' => null,
                ],
                'recent_signups' => [],
                'top_fans' => [],
                'earnings_chart' => [],
            ],
        ]);
    }

    /**
     * GET /api/artist/referrals/link
     */
    public function referralLink(Request $request): JsonResponse
    {
        $result = $this->requireArtist($request);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $artist = $result;

        return response()->json([
            'data' => [
                'referral_code' => $artist->slug,
                'referral_link' => url("/join/{$artist->slug}"),
                'branded_link' => url("/join/{$artist->slug}"),
                'qr_code_url' => null,
            ],
        ]);
    }

    /**
     * GET /api/artist/referrals/fans
     */
    public function referralFans(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            'stats' => ['total' => 0, 'active' => 0, 'pending' => 0, 'inactive' => 0],
        ]);
    }

    /**
     * GET /api/artist/referrals/earnings
     */
    public function referralEarnings(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'total_commission' => 0,
                'pending_payout' => 0,
                'paid_out' => 0,
                'commission_rate' => 0,
                'transactions' => [],
            ],
        ]);
    }

    /**
     * GET /api/artist/referrals/promo-materials
     */
    public function promoMaterials(Request $request): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * POST /api/artist/referrals/promo-materials/generate
     */
    public function generatePromoMaterial(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => 1,
                'type' => $request->type ?? 'banner',
                'title' => 'Generated Material',
                'description' => 'Promotional material',
                'image_url' => null,
                'download_url' => null,
                'dimensions' => '1080x1080',
                'platform' => $request->platform ?? 'universal',
            ],
        ]);
    }

    /**
     * POST /api/artist/referrals/share
     */
    public function trackShare(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Share tracked.']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function formatPaymentTransaction(Payment $payment): array
    {
        $date = $payment->completed_at ?? $payment->created_at;

        return [
            'id' => $payment->id,
            'type' => 'earning',
            'description' => ucfirst($payment->payment_type).' - '.($payment->description ?: 'Song payment'),
            'amount' => (float) $payment->amount,
            'date' => $date?->toIso8601String(),
            'status' => $payment->status,
            'sort_timestamp' => $date?->timestamp ?? 0,
        ];
    }

    private function formatArtistRevenueTransaction(ArtistRevenue $revenue): array
    {
        $date = $revenue->created_at ?? $revenue->revenue_date;
        $details = $this->decodeRevenueNotes($revenue->notes);

        if (is_array($details)) {
            $details['revenue_type'] = $revenue->revenue_type;
            $details['source_song_id'] = $revenue->sourceable_type === Song::class ? $revenue->sourceable_id : null;
        }

        $sourceTitle = $revenue->revenueSource?->title;
        if (! $sourceTitle && $revenue->sourceable_type === Song::class && $revenue->sourceable_id) {
            $sourceTitle = Song::query()->whereKey($revenue->sourceable_id)->value('title');
        }

        return [
            'id' => $revenue->id,
            'type' => 'stream',
            'description' => 'Streaming - '.($sourceTitle ?: 'Song stream'),
            'amount' => (float) $revenue->net_amount,
            'gross_amount' => (float) $revenue->amount_ugx,
            'platform_fee' => (float) $revenue->platform_fee,
            'date' => $date?->toIso8601String(),
            'status' => $revenue->status,
            'details' => $details,
            'sort_timestamp' => $date?->timestamp ?? 0,
        ];
    }

    private function decodeRevenueNotes(?string $notes): ?array
    {
        if (! $notes) {
            return null;
        }

        $decoded = json_decode($notes, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [
            'note' => $notes,
        ];
    }

    private function songUploadDiskName(): string
    {
        return StorageHelper::mediaDisk();
    }

    private function songUploadDisk()
    {
        return Storage::disk($this->songUploadDiskName());
    }

    private function songUploadClient($disk)
    {
        if ($disk instanceof LaravelAwsS3V3Adapter) {
            return $disk->getClient();
        }

        // FilesystemAdapter may proxy getClient() via __call, so method_exists()
        // can return false even when the client is available.
        try {
            return $disk->getClient();
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve upload storage client', [
                'disk' => $this->songUploadDiskName(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function maxSongAudioBytes(): int
    {
        return (int) config('music.storage.limits.max_audio_size', 500 * 1024 * 1024);
    }

    private function maxSongAudioKilobytes(): int
    {
        return (int) ceil($this->maxSongAudioBytes() / 1024);
    }

    private function maxSongArtworkBytes(): int
    {
        return (int) config('music.storage.limits.max_artwork_size', 10 * 1024 * 1024);
    }

    private function buildDirectUploadTarget(string $kind, int $userId, string $extension, ?string $contentType): ?array
    {
        $diskName = $this->songUploadDiskName();

        if (app()->environment('testing')) {
            $key = $this->directUploadKey($kind, $userId, $extension);

            return [
                'disk' => $diskName,
                'method' => 'POST',
                'key' => $key,
                'upload_url' => "https://example.test/direct-upload/{$key}",
                'fields' => [
                    'key' => $key,
                ],
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ];
        }

        $disk = $this->songUploadDisk();
        $client = $this->songUploadClient($disk);
        if ($client === null) {
            return null;
        }

        $key = $this->directUploadKey($kind, $userId, $extension);
        $bucket = config("filesystems.disks.{$diskName}.bucket");
        $formInputs = array_filter([
            'key' => $key,
            'success_action_status' => '201',
            'Content-Type' => $contentType,
        ]);

        // Artwork must be publicly readable so browsers can load it directly.
        // Audio files stay private and use signed streaming URLs instead.
        if ($kind === 'cover') {
            $formInputs['acl'] = 'public-read';
        }

        $conditions = [
            ['bucket' => $bucket],
            ['key' => $key],
            ['success_action_status' => '201'],
            ['content-length-range', 1, $kind === 'audio' ? $this->maxSongAudioBytes() : $this->maxSongArtworkBytes()],
        ];

        if ($kind === 'cover') {
            $conditions[] = ['acl' => 'public-read'];
        }

        if ($contentType) {
            $conditions[] = ['Content-Type' => $contentType];
        }

        $postObject = new PostObjectV4(
            $client,
            $bucket,
            $formInputs,
            $conditions,
            '+30 minutes'
        );

        return [
            'disk' => $diskName,
            'method' => 'POST',
            'key' => $key,
            'upload_url' => $postObject->getFormAttributes()['action'],
            'fields' => $postObject->getFormInputs(),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];
    }

    private function directUploadKey(string $kind, int $userId, string $extension): string
    {
        $directory = $kind === 'audio' ? 'songs/audio/direct' : 'songs/artwork/direct';

        return sprintf('%s/%d/%s.%s', $directory, $userId, Str::uuid(), $extension);
    }

    private function resolveSongAudioUpload(Request $request, array $validated): array
    {
        if (! empty($validated['uploaded_audio_key'])) {
            return $this->resolveDirectSongUpload(
                kind: 'audio',
                key: $validated['uploaded_audio_key'],
                originalName: $validated['uploaded_audio_original_name'] ?? null,
                declaredSizeBytes: Arr::get($validated, 'uploaded_audio_size_bytes')
            );
        }

        if (! empty($validated['uploaded_audio_session_id'])) {
            return $this->resolveSongUploadSessionReference(
                request: $request,
                sessionUuid: $validated['uploaded_audio_session_id']
            );
        }

        $audioFile = $request->file('audio');
        if (! $audioFile) {
            abort(response()->json([
                'message' => 'Audio file is required.',
                'errors' => ['audio' => ['An audio file is required.']],
            ], 422));
        }

        if (! $audioFile->isValid()) {
            abort(response()->json([
                'message' => 'File upload failed: '.$audioFile->getErrorMessage(),
                'error_code' => $audioFile->getError(),
            ], 422));
        }

        $audioExt = $audioFile->getClientOriginalExtension();
        $audioSize = $audioFile->getSize();
        $audioFileName = Str::uuid().'.'.$audioExt;
        $audioPath = 'songs/audio/'.$audioFileName;

        try {
            $this->songUploadDisk()->put($audioPath, fopen($audioFile->getPathname(), 'r'));
        } catch (\Throwable $e) {
            Log::error('Song audio upload failed', [
                'error' => $e->getMessage(),
                'disk' => $this->songUploadDiskName(),
                'path' => $audioPath,
            ]);

            abort(response()->json([
                'message' => 'Failed to upload audio file. Please try again.',
            ], 500));
        }

        return [$audioPath, $audioExt, $audioSize];
    }

    private function resolveSongArtworkUpload(Request $request, array $validated): ?array
    {
        if (! empty($validated['uploaded_cover_key'])) {
            return $this->resolveDirectSongUpload(
                kind: 'cover',
                key: $validated['uploaded_cover_key'],
                originalName: $validated['uploaded_cover_original_name'] ?? null,
                declaredSizeBytes: null
            );
        }

        $coverFile = $request->file('cover');
        if (! $coverFile || ! $coverFile->isValid()) {
            return null;
        }

        $coverExt = $coverFile->getClientOriginalExtension();
        $coverFileName = Str::uuid().'.'.$coverExt;

        try {
            $artworkPath = StorageHelper::store($coverFile, 'songs/artwork', $coverFileName);
        } catch (\Throwable $e) {
            Log::warning('Song artwork upload failed', [
                'error' => $e->getMessage(),
                'path' => 'songs/artwork/'.$coverFileName,
            ]);

            return null;
        }

        return [$artworkPath, $coverExt, $coverFile->getSize()];
    }

    private function resolveDirectSongUpload(string $kind, string $key, ?string $originalName, ?int $declaredSizeBytes): array
    {
        $allowedPrefixes = [
            'audio' => 'songs/audio/direct/',
            'cover' => 'songs/artwork/direct/',
        ];
        $allowedExtensions = [
            'audio' => $this->allowedDirectUploadExtensions('audio'),
            'cover' => $this->allowedDirectUploadExtensions('cover'),
        ];
        $maxBytes = [
            'audio' => $this->maxSongAudioBytes(),
            'cover' => $this->maxSongArtworkBytes(),
        ];

        if (! Str::startsWith($key, $allowedPrefixes[$kind])) {
            Log::warning('Artist direct upload rejected invalid key prefix', [
                'kind' => $kind,
                'key' => $key,
            ]);

            abort(response()->json([
                'message' => 'Uploaded file reference is invalid.',
            ], 422));
        }

        $extension = Str::lower(pathinfo($key, PATHINFO_EXTENSION));
        if ($extension === '' || ! in_array($extension, $allowedExtensions[$kind], true)) {
            Log::warning('Artist direct upload rejected unsupported stored extension', [
                'kind' => $kind,
                'key' => $key,
                'extension' => $extension,
            ]);

            abort(response()->json([
                'message' => 'Uploaded file type is not supported.',
            ], 422));
        }

        $disk = $this->songUploadDisk();
        if (! $disk->exists($key)) {
            Log::warning('Artist direct upload object missing from storage', [
                'kind' => $kind,
                'key' => $key,
                'disk' => $this->songUploadDiskName(),
            ]);

            abort(response()->json([
                'message' => 'Uploaded file could not be found in cloud storage. Please upload it again.',
            ], 422));
        }

        $actualSizeBytes = (int) $disk->size($key);
        if ($actualSizeBytes < 1 || $actualSizeBytes > $maxBytes[$kind]) {
            Log::warning('Artist direct upload rejected invalid stored size', [
                'kind' => $kind,
                'key' => $key,
                'actual_size_bytes' => $actualSizeBytes,
                'max_size_bytes' => $maxBytes[$kind],
            ]);

            abort(response()->json([
                'message' => sprintf(
                    '%s files must be %s or smaller.',
                    Str::headline($kind),
                    $this->formatBytes($maxBytes[$kind])
                ),
            ], 422));
        }

        if ($declaredSizeBytes !== null && $declaredSizeBytes !== $actualSizeBytes) {
            Log::warning('Artist direct upload size mismatch', [
                'kind' => $kind,
                'key' => $key,
                'declared_size_bytes' => $declaredSizeBytes,
                'actual_size_bytes' => $actualSizeBytes,
            ]);
        }

        return [$key, $extension, $actualSizeBytes, $originalName];
    }

    private function findOwnedSongUploadSession(Request $request, string $sessionUuid): MediaUploadSession
    {
        $session = MediaUploadSession::query()
            ->where('uuid', $sessionUuid)
            ->where('user_id', $request->user()->id)
            ->where('kind', 'audio')
            ->firstOrFail();

        if ($session->isExpired()) {
            abort(response()->json([
                'message' => 'This upload session has expired. Please start the upload again.',
            ], 422));
        }

        return $session;
    }

    private function resolveSongUploadSessionReference(Request $request, string $sessionUuid): array
    {
        $session = $this->findOwnedSongUploadSession($request, $sessionUuid);

        if (! $session->isCompleted()) {
            abort(response()->json([
                'message' => 'The upload session has not finished uploading yet.',
            ], 422));
        }

        if ($session->isConsumed()) {
            abort(response()->json([
                'message' => 'This upload session has already been used.',
            ], 422));
        }

        return $this->resolveDirectSongUpload(
            kind: 'audio',
            key: $session->target_key,
            originalName: $session->original_filename,
            declaredSizeBytes: $session->size_bytes
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1).' GB';
        }

        return number_format($bytes / (1024 * 1024), 0).' MB';
    }

    private function allowedDirectUploadExtensions(string $kind): array
    {
        return $kind === 'audio'
            ? ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'mp4', 'webm', 'wma', 'opus']
            : ['jpg', 'jpeg', 'png', 'webp'];
    }

    private function resolveDirectUploadExtension(string $kind, string $filename, ?string $contentType): ?string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = $this->allowedDirectUploadExtensions($kind);

        if ($extension !== '' && in_array($extension, $allowed, true)) {
            return $extension;
        }

        $mimeMap = $kind === 'audio'
            ? [
                'audio/mpeg' => 'mp3',
                'audio/mp3' => 'mp3',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/wave' => 'wav',
                'audio/flac' => 'flac',
                'audio/x-flac' => 'flac',
                'audio/aac' => 'aac',
                'audio/x-aac' => 'aac',
                'audio/mp4' => 'm4a',
                'audio/m4a' => 'm4a',
                'audio/x-m4a' => 'm4a',
                'video/mp4' => 'm4a',
                'audio/ogg' => 'ogg',
                'audio/vorbis' => 'ogg',
                'audio/webm' => 'webm',
                'audio/x-ms-wma' => 'wma',
                'audio/wma' => 'wma',
                'audio/opus' => 'opus',
            ]
            : [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

        $normalizedContentType = $contentType ? Str::lower(trim($contentType)) : null;

        return $normalizedContentType && isset($mimeMap[$normalizedContentType])
            ? $mimeMap[$normalizedContentType]
            : null;
    }

    private function formatDuration(?int $seconds): string
    {
        if (! $seconds) {
            return '0:00';
        }
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d', $mins, $secs);
    }
}
