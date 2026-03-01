<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\ISRCCode;
use App\Models\Song;
use App\Services\Music\ISRCService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ISRCController extends Controller
{
    public function __construct(
        protected ISRCService $isrcService,
    ) {}

    /**
     * POST /api/songs/{song}/generate-isrc
     *
     * Generate an ISRC code for a specific song.
     */
    public function generateForSong(Request $request, Song $song): JsonResponse
    {
        try {
            $user = $request->user();

            // Ownership check
            if ($song->user_id !== $user->id && $song->artist_id !== $user->artist?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this song.',
                ], 403);
            }

            // Check if song already has an ISRC
            if ($song->isrc_code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song already has an ISRC code.',
                    'data' => ['isrc_code' => $song->isrc_code],
                ], 422);
            }

            $isrcCode = $this->isrcService->generate($song);
            $song->update(['isrc_code' => $isrcCode]);

            return response()->json([
                'success' => true,
                'message' => 'ISRC code generated successfully.',
                'data' => [
                    'song_id' => $song->id,
                    'isrc_code' => $isrcCode,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate ISRC code: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/albums/{album}/generate-isrc
     *
     * Generate ISRC for the first song in an album that lacks one.
     */
    public function generateForAlbum(Request $request, Album $album): JsonResponse
    {
        try {
            $user = $request->user();

            if ($album->artist_id !== $user->artist?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this album.',
                ], 403);
            }

            $song = $album->songs()->whereNull('isrc_code')->first();

            if (! $song) {
                return response()->json([
                    'success' => false,
                    'message' => 'All songs in this album already have ISRC codes.',
                ], 422);
            }

            $isrcCode = $this->isrcService->generate($song);
            $song->update(['isrc_code' => $isrcCode]);

            return response()->json([
                'success' => true,
                'message' => 'ISRC code generated for song.',
                'data' => [
                    'song_id' => $song->id,
                    'song_title' => $song->title,
                    'isrc_code' => $isrcCode,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate ISRC code: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/albums/{album}/generate-isrc-batch
     *
     * Generate ISRC codes for all songs in an album that lack one.
     */
    public function generateBatchForAlbum(Request $request, Album $album): JsonResponse
    {
        try {
            $user = $request->user();

            if ($album->artist_id !== $user->artist?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this album.',
                ], 403);
            }

            $songs = $album->songs()->whereNull('isrc_code')->get();

            if ($songs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'All songs in this album already have ISRC codes.',
                ], 422);
            }

            $results = $this->isrcService->bulkGenerate($songs->all());

            // Update songs with generated codes
            $generated = [];
            foreach ($results as $songId => $code) {
                if ($code) {
                    Song::where('id', $songId)->update(['isrc_code' => $code]);
                    $generated[] = ['song_id' => $songId, 'isrc_code' => $code];
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($generated).' ISRC codes generated.',
                'data' => [
                    'total_songs' => $songs->count(),
                    'generated' => count($generated),
                    'failed' => $songs->count() - count($generated),
                    'codes' => $generated,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate batch ISRC codes: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/isrc/{isrc}/register
     *
     * Register an existing ISRC code (mark as registered with authority).
     */
    public function register(Request $request, string $isrc): JsonResponse
    {
        try {
            $isrcRecord = ISRCCode::where('isrc_code', $isrc)
                ->orWhere('formatted_isrc', $isrc)
                ->firstOrFail();

            if ($isrcRecord->status === 'registered') {
                return response()->json([
                    'success' => false,
                    'message' => 'ISRC code is already registered.',
                ], 422);
            }

            $isrcRecord->update([
                'status' => 'registered',
                'registered_at' => now(),
                'registration_authority' => $request->input('authority', 'TesoTunes'),
                'registration_reference' => $request->input('reference'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ISRC code registered successfully.',
                'data' => $isrcRecord->fresh(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ISRC code not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register ISRC code.',
            ], 500);
        }
    }

    /**
     * POST /api/isrc/{isrc}/clearance
     * POST /api/isrc/{isrc}/clear-for-distribution
     *
     * Clear an ISRC code for distribution.
     */
    public function clearance(Request $request, string $isrc): JsonResponse
    {
        try {
            $isrcRecord = ISRCCode::where('isrc_code', $isrc)
                ->orWhere('formatted_isrc', $isrc)
                ->firstOrFail();

            if ($isrcRecord->cleared_for_distribution) {
                return response()->json([
                    'success' => false,
                    'message' => 'ISRC code is already cleared for distribution.',
                ], 422);
            }

            $isrcRecord->update([
                'cleared_for_distribution' => true,
                'distribution_cleared_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ISRC code cleared for distribution.',
                'data' => $isrcRecord->fresh(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ISRC code not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear ISRC code for distribution.',
            ], 500);
        }
    }

    /**
     * POST /api/isrc/bulk
     *
     * Bulk generate ISRC codes for multiple songs.
     */
    public function bulkOperation(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'song_ids' => 'required|array|max:50',
                'song_ids.*' => 'integer|exists:songs,id',
            ]);

            $user = $request->user();
            $songs = Song::whereIn('id', $validated['song_ids'])
                ->where('user_id', $user->id)
                ->whereNull('isrc_code')
                ->get();

            if ($songs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No eligible songs found (must be owned by you and lack an ISRC code).',
                ], 422);
            }

            $results = $this->isrcService->bulkGenerate($songs->all());

            $generated = [];
            foreach ($results as $songId => $code) {
                if ($code) {
                    Song::where('id', $songId)->update(['isrc_code' => $code]);
                    $generated[] = ['song_id' => $songId, 'isrc_code' => $code];
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($generated).' ISRC codes generated.',
                'data' => [
                    'requested' => count($validated['song_ids']),
                    'eligible' => $songs->count(),
                    'generated' => count($generated),
                    'codes' => $generated,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk ISRC operation.',
            ], 500);
        }
    }

    /**
     * POST /api/isrc/bulk-register
     *
     * Bulk register multiple ISRC codes.
     */
    public function bulkRegister(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'isrc_codes' => 'required|array|max:50',
                'isrc_codes.*' => 'string',
                'authority' => 'nullable|string|max:100',
            ]);

            $registered = 0;
            $errors = [];

            foreach ($validated['isrc_codes'] as $code) {
                $record = ISRCCode::where('isrc_code', $code)
                    ->orWhere('formatted_isrc', $code)
                    ->first();

                if (! $record) {
                    $errors[] = ['code' => $code, 'reason' => 'Not found'];

                    continue;
                }

                if ($record->status === 'registered') {
                    $errors[] = ['code' => $code, 'reason' => 'Already registered'];

                    continue;
                }

                $record->update([
                    'status' => 'registered',
                    'registered_at' => now(),
                    'registration_authority' => $validated['authority'] ?? 'TesoTunes',
                ]);
                $registered++;
            }

            return response()->json([
                'success' => true,
                'message' => "{$registered} ISRC codes registered.",
                'data' => [
                    'total' => count($validated['isrc_codes']),
                    'registered' => $registered,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk register ISRC codes.',
            ], 500);
        }
    }

    /**
     * POST /api/isrc/bulk-clear-distribution
     *
     * Bulk clear multiple ISRC codes for distribution.
     */
    public function bulkClearDistribution(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'isrc_codes' => 'required|array|max:50',
                'isrc_codes.*' => 'string',
            ]);

            $cleared = 0;
            $errors = [];

            foreach ($validated['isrc_codes'] as $code) {
                $record = ISRCCode::where('isrc_code', $code)
                    ->orWhere('formatted_isrc', $code)
                    ->first();

                if (! $record) {
                    $errors[] = ['code' => $code, 'reason' => 'Not found'];

                    continue;
                }

                if ($record->cleared_for_distribution) {
                    $errors[] = ['code' => $code, 'reason' => 'Already cleared'];

                    continue;
                }

                $record->update([
                    'cleared_for_distribution' => true,
                    'distribution_cleared_at' => now(),
                ]);
                $cleared++;
            }

            return response()->json([
                'success' => true,
                'message' => "{$cleared} ISRC codes cleared for distribution.",
                'data' => [
                    'total' => count($validated['isrc_codes']),
                    'cleared' => $cleared,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk clear ISRC codes.',
            ], 500);
        }
    }

    /**
     * GET /api/isrc
     *
     * List ISRC codes for the authenticated user's songs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = min((int) $request->get('per_page', 20), 100);

            $query = ISRCCode::with(['song:id,title,slug', 'artist:id,name,stage_name'])
                ->when($user->artist, function ($q) use ($user) {
                    $q->where('artist_id', $user->artist->id);
                }, function ($q) use ($user) {
                    $q->whereHas('song', fn ($s) => $s->where('user_id', $user->id));
                })
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('year'), fn ($q) => $q->where('year_code', $request->year))
                ->when($request->boolean('cleared'), fn ($q) => $q->clearedForDistribution())
                ->orderByDesc('created_at');

            $isrcCodes = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $isrcCodes->items(),
                'meta' => [
                    'current_page' => $isrcCodes->currentPage(),
                    'last_page' => $isrcCodes->lastPage(),
                    'per_page' => $isrcCodes->perPage(),
                    'total' => $isrcCodes->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list ISRC codes.',
            ], 500);
        }
    }

    /**
     * GET /api/isrc/search
     *
     * Search ISRC codes by code, song title, or artist name.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'q' => 'required|string|min:2|max:100',
            ]);

            $term = $request->get('q');
            $escapedTerm = str_replace(['%', '_'], ['\\%', '\\_'], $term);

            $results = ISRCCode::with(['song:id,title,slug', 'artist:id,name,stage_name'])
                ->where(function ($q) use ($escapedTerm) {
                    $q->where('isrc_code', 'like', "%{$escapedTerm}%")
                        ->orWhere('formatted_isrc', 'like', "%{$escapedTerm}%")
                        ->orWhereHas('song', fn ($s) => $s->where('title', 'like', "%{$escapedTerm}%"))
                        ->orWhereHas('artist', fn ($a) => $a->where('name', 'like', "%{$escapedTerm}%")
                            ->orWhere('stage_name', 'like', "%{$escapedTerm}%"));
                })
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $results,
                'meta' => ['total' => $results->count()],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed.',
            ], 500);
        }
    }

    /**
     * GET /api/isrc/export
     *
     * Export ISRC codes as CSV for the authenticated user's songs.
     */
    public function export(Request $request)
    {
        try {
            $user = $request->user();

            $records = ISRCCode::with(['song:id,title', 'artist:id,name,stage_name'])
                ->when($user->artist, function ($q) use ($user) {
                    $q->where('artist_id', $user->artist->id);
                }, function ($q) use ($user) {
                    $q->whereHas('song', fn ($s) => $s->where('user_id', $user->id));
                })
                ->orderByDesc('created_at')
                ->get();

            $csv = "ISRC Code,Song Title,Artist,Status,Cleared for Distribution,Registered At\n";
            foreach ($records as $record) {
                $csv .= implode(',', [
                    $record->formatted_isrc ?? $record->isrc_code,
                    '"'.str_replace('"', '""', $record->song?->title ?? 'N/A').'"',
                    '"'.str_replace('"', '""', $record->artist?->stage_name ?? $record->artist?->name ?? 'N/A').'"',
                    $record->status ?? 'pending',
                    $record->cleared_for_distribution ? 'Yes' : 'No',
                    $record->registered_at?->toDateString() ?? '',
                ])."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="isrc_codes_'.now()->format('Y-m-d').'.csv"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export ISRC codes.',
            ], 500);
        }
    }

    /**
     * POST /api/isrc/check-duplicate
     *
     * Check if an ISRC code already exists.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'isrc_code' => 'required|string',
            ]);

            $exists = $this->isrcService->exists($validated['isrc_code']);

            $record = null;
            if ($exists) {
                $record = ISRCCode::with(['song:id,title', 'artist:id,name,stage_name'])
                    ->where('isrc_code', $validated['isrc_code'])
                    ->orWhere('formatted_isrc', $validated['isrc_code'])
                    ->first();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'exists' => $exists,
                    'valid_format' => $this->isrcService->validateFormat($validated['isrc_code']),
                    'existing_record' => $record,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check ISRC code.',
            ], 500);
        }
    }

    /**
     * GET /api/isrc/analytics
     *
     * ISRC analytics for the authenticated user's catalogue.
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $baseQuery = ISRCCode::query()
                ->when($user->artist, function ($q) use ($user) {
                    $q->where('artist_id', $user->artist->id);
                }, function ($q) use ($user) {
                    $q->whereHas('song', fn ($s) => $s->where('user_id', $user->id));
                });

            $total = (clone $baseQuery)->count();
            $registered = (clone $baseQuery)->registered()->count();
            $pending = (clone $baseQuery)->pending()->count();
            $cleared = (clone $baseQuery)->clearedForDistribution()->count();

            // Count by year
            $byYear = (clone $baseQuery)
                ->selectRaw('year_code, count(*) as count')
                ->groupBy('year_code')
                ->orderByDesc('year_code')
                ->pluck('count', 'year_code');

            // Recent activity (last 30 days)
            $recentCount = (clone $baseQuery)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'registered' => $registered,
                    'pending' => $pending,
                    'cleared_for_distribution' => $cleared,
                    'not_cleared' => $total - $cleared,
                    'by_year' => $byYear,
                    'recent_30d' => $recentCount,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load ISRC analytics.',
            ], 500);
        }
    }
}
