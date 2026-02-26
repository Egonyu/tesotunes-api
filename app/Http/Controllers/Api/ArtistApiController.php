<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtistApiController extends Controller
{
    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Get the authenticated user's artist record.
     */
    private function artist(Request $request): ?Artist
    {
        return Artist::where('user_id', $request->user()->id)->first();
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
                'artwork' => $song->artwork ? url('storage/'.$song->artwork) : null,
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

        return response()->json([
            'data' => [
                'artist' => [
                    'id' => $artist->id,
                    'name' => $artist->stage_name,
                    'avatar' => $artist->avatar ? url('storage/'.$artist->avatar) : null,
                    'is_verified' => (bool) $artist->is_verified,
                ],
                'stats' => $stats,
                'recent_songs' => $recentSongs,
                'pending_actions' => [],
                'chart_data' => [],
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
                'id' => $song->id,
                'title' => $song->title,
                'cover' => $song->artwork ? url('storage/'.$song->artwork) : null,
                'album' => $song->album ? $song->album->title : null,
                'plays' => (int) ($song->play_count ?? 0),
                'downloads' => (int) ($song->download_count ?? 0),
                'duration' => $this->formatDuration($song->duration_seconds),
                'status' => $song->status ?? 'draft',
                'release_date' => $song->release_date ?? $song->created_at->toDateString(),
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
                'id' => $song->id,
                'title' => $song->title,
                'cover' => $song->artwork ? url('storage/'.$song->artwork) : null,
                'album' => $song->album ? $song->album->title : null,
                'album_id' => $song->album_id,
                'plays' => (int) ($song->play_count ?? 0),
                'downloads' => (int) ($song->download_count ?? 0),
                'duration' => $this->formatDuration($song->duration_seconds),
                'status' => $song->status ?? 'draft',
                'release_date' => $song->release_date ?? $song->created_at->toDateString(),
                'lyrics' => $song->lyrics,
                'description' => $song->description,
                'is_explicit' => (bool) $song->is_explicit,
                'genre' => $song->primaryGenre ? $song->primaryGenre->name : null,
                'price' => $song->price,
                'is_free' => (bool) $song->is_free,
            ],
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
        if (! $artist->can_upload) {
            return response()->json([
                'message' => 'You are not allowed to upload songs at this time.',
            ], 403);
        }

        // Check monthly upload limit
        if (! $artist->canUploadThisMonth()) {
            return response()->json([
                'message' => 'You have reached your monthly upload limit ('.$artist->monthly_upload_limit.' songs).',
            ], 429);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            // Accept both 'audio' and 'audio_file' field names
            'audio' => 'required_without:audio_file|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:102400',
            'audio_file' => 'required_without:audio|file|mimes:mp3,wav,flac,aac,m4a,ogg|max:102400',
            // Accept both 'cover' and 'cover_image' field names
            'cover' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'album_id' => 'nullable|integer|exists:albums,id',
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

        // Handle audio file (accept either field name) - MOVE IMMEDIATELY to avoid temp file cleanup
        $audioFile = $request->file('audio') ?? $request->file('audio_file');
        if (! $audioFile) {
            return response()->json([
                'message' => 'Audio file is required.',
                'errors' => ['audio' => ['An audio file is required.']],
            ], 422);
        }

        // Check if file is valid
        if (! $audioFile->isValid()) {
            return response()->json([
                'message' => 'File upload failed: '.$audioFile->getErrorMessage(),
                'error_code' => $audioFile->getError(),
            ], 422);
        }

        // Capture file info BEFORE moving (move() invalidates the file object)
        $audioExt = $audioFile->getClientOriginalExtension();
        $audioSize = $audioFile->getSize();
        $audioFileName = Str::uuid().'.'.$audioExt;
        $audioPath = 'songs/audio/'.$audioFileName;

        // Use move() instead of store() to work around Windows temp file issues
        $destinationPath = storage_path('app/public/songs/audio');
        if (! is_dir($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
        $audioFile->move($destinationPath, $audioFileName);

        // Handle cover image (accept either field name)
        $artworkPath = null;
        $coverFile = $request->file('cover') ?? $request->file('cover_image');
        if ($coverFile && $coverFile->isValid()) {
            $coverExt = $coverFile->getClientOriginalExtension();
            $coverFileName = Str::uuid().'.'.$coverExt;
            $artworkPath = 'songs/artwork/'.$coverFileName;

            $coverDestination = storage_path('app/public/songs/artwork');
            if (! is_dir($coverDestination)) {
                mkdir($coverDestination, 0755, true);
            }
            $coverFile->move($coverDestination, $coverFileName);
        }

        // Generate slug
        $slug = Str::slug($validated['title']);
        $originalSlug = $slug;
        $counter = 1;
        while (Song::where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

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

        $song = Song::create([
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
            'file_format' => $audioExt,
            'file_size_bytes' => $audioSize,
            'processing_status' => ['status' => 'completed', 'progress' => 100],
            'visibility' => 'public',
            'duration_seconds' => 0,
        ]);

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

        return response()->json([
            'message' => 'Song uploaded successfully!',
            'data' => [
                'id' => $song->id,
                'title' => $song->title,
                'status' => $song->status,
                'artwork_url' => $artworkPath ? url('storage/'.$artworkPath) : null,
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
            'lyrics' => 'nullable|string',
            'description' => 'nullable|string|max:2000',
            'release_date' => 'nullable|date',
            'is_explicit' => 'nullable',
            'price' => 'nullable|numeric|min:0',
            'album_id' => 'nullable|integer|exists:albums,id',
        ]);

        $song->update($validated);

        return response()->json([
            'message' => 'Song updated successfully.',
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

        $count = Song::where('artist_id', $artist->id)
            ->whereIn('id', $validated['song_ids'])
            ->delete();

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
        ]);

        $artworkPath = null;
        if ($request->hasFile('cover_image')) {
            $artworkPath = $request->file('cover_image')->store('albums/artwork', 'public');
        }

        $slug = Str::slug($validated['title']);
        $originalSlug = $slug;
        $counter = 1;
        while (Album::where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        $album = Album::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'artist_id' => $artist->id,
            'artwork' => $artworkPath,
            'description' => $validated['description'] ?? null,
            'release_date' => $validated['release_date'] ?? null,
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
                'avatar' => $artist->avatar ? url('storage/'.$artist->avatar) : null,
                'banner' => $artist->cover_image ? url('storage/'.$artist->cover_image) : null,
                'country' => null,
                'city' => null,
                'website_url' => $artist->website_url,
                'social_links' => $artist->social_links,
                'is_verified' => (bool) $artist->is_verified,
                'verification_status' => $artist->verification_status ?? 'pending',
                'payout_phone_number' => $artist->payout_phone_number,
                'can_upload' => (bool) $artist->can_upload,
                'auto_publish' => (bool) $artist->auto_publish,
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
        ]);

        $artist->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
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

        return response()->json([
            'data' => [
                'stats' => [
                    'balance' => (float) ($artist->earnings_balance ?? 0),
                    'pending_earnings' => 0,
                    'total_earnings' => (float) ($artist->total_revenue ?? 0),
                    'this_month' => 0,
                    'monthly_change' => 0,
                ],
                'earnings_sources' => [
                    ['source' => 'Streaming', 'amount' => 0, 'percentage' => 0],
                    ['source' => 'Downloads', 'amount' => 0, 'percentage' => 0],
                    ['source' => 'Store', 'amount' => 0, 'percentage' => 0],
                ],
                'transactions' => [],
            ],
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

        if ($validated['amount'] > ($artist->earnings_balance ?? 0)) {
            return response()->json([
                'message' => 'Insufficient balance.',
            ], 422);
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

        $topSongs = Song::where('artist_id', $artist->id)
            ->orderByDesc('play_count')
            ->limit(10)
            ->get()
            ->map(fn ($song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artwork' => $song->artwork ? url('storage/'.$song->artwork) : null,
                'play_count' => (int) ($song->play_count ?? 0),
                'download_count' => (int) ($song->download_count ?? 0),
            ]);

        return response()->json([
            'data' => [
                'period' => "{$period} days",
                'plays_over_time' => [],
                'top_songs' => $topSongs,
                'demographics' => [
                    'countries' => [],
                    'devices' => [],
                ],
                'engagement' => [
                    'total_plays' => (int) ($artist->total_plays_count ?? 0),
                    'unique_listeners' => 0,
                    'avg_listen_time' => 0,
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
