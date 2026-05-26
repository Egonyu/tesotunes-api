<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Payment;
use App\Models\Song;
use App\Models\SongPurchase;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Payment\ZengaPayService;
use App\Services\Settings\ArtistSettingsService;
use App\Services\SongService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SongController extends Controller
{
    public function __construct(
        protected SongService $songService,
        protected ZengaPayService $zengaPayService,
    ) {}

    /**
     * GET /api/songs
     * Paginated list of published songs.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', $request->get('limit', 20)), 100);
        $sort = (string) $request->get('sort', '-created_at');
        $sortField = ltrim($sort, '-');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $allowedSortFields = [
            'created_at',
            'updated_at',
            'play_count',
            'like_count',
            'download_count',
            'title',
            'release_date',
        ];
        $sortField = in_array($sortField, $allowedSortFields, true) ? $sortField : 'created_at';

        $songs = Song::with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->whereHas('artist', fn ($q) => $q->whereIn('status', Artist::VISIBLE_STATUSES))
            ->when($request->filled('genre'), fn ($q) => $q->where('primary_genre_id', $request->genre))
            ->when($request->filled('artist'), fn ($q) => $q->where('artist_id', $request->artist))
            ->when($request->filled('album'), fn ($q) => $q->where('album_id', $request->album))
            ->when($request->filled('is_free'), fn ($q) => $q->where('is_free', $request->boolean('is_free')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('title', 'like', '%'.escape_like($request->search).'%');
            })
            ->when($request->filled('period'), function ($q) use ($request) {
                $days = match ((string) $request->get('period')) {
                    'day', 'today' => 1,
                    'week' => 7,
                    'month' => 30,
                    'year' => 365,
                    default => null,
                };

                if ($days !== null) {
                    $q->where('created_at', '>=', now()->subDays($days));
                }
            })
            ->orderBy($sortField, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }

    /**
     * GET /api/songs/{song}
     * Single song by ID, slug, or UUID.
     */
    public function show(string $song)
    {
        $record = Song::with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->where(function ($q) use ($song) {
                $q->where('id', $song)
                    ->orWhere('slug', $song)
                    ->orWhere('uuid', $song);
            })
            ->firstOrFail();

        return new SongResource($record);
    }

    public function trending(Request $request): JsonResponse
    {
        try {
            $songs = $this->songService->getTrendingSongs(
                $request->get('days', 7),
                $request->get('limit', 20)
            );

            return SongResource::collection($songs)->response();

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch trending songs',
            ], 500);
        }
    }

    public function newReleases(Request $request): JsonResponse
    {
        try {
            $songs = $this->songService->getNewReleases(
                $request->get('days', 30),
                $request->get('limit', 20)
            );

            return SongResource::collection($songs)->response();

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch new releases',
            ], 500);
        }
    }

    public function byGenre(Request $request, string $genre): JsonResponse
    {
        try {
            $songs = $this->songService->getSongsByGenre(
                $genre,
                $request->get('per_page', 20)
            );

            return SongResource::collection($songs)->response();

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch songs by genre',
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q');

            if (! $query) {
                return response()->json([
                    'message' => 'Search query is required',
                ], 400);
            }

            $songs = $this->songService->searchSongs(
                $query,
                $request->get('per_page', 20)
            );

            return SongResource::collection($songs)->response();

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Search failed',
            ], 500);
        }
    }

    public function recordPlay(Request $request, Song $song): JsonResponse
    {
        try {
            $user = Auth::user();

            $playData = [
                'duration_played_seconds' => $request->get('play_duration_seconds', $request->get('duration_played_seconds', 0)),
                'completed' => $request->boolean('completed', false),
                'device_type' => $request->get('device_type', 'web'),
                'quality' => $request->get('quality', '128'),
            ];

            $playHistory = $this->songService->recordPlay($song, $user, $playData);

            return response()->json([
                'success' => true,
                'message' => 'Play recorded successfully',
                'data' => $playHistory,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], $e->getCode() === 403 ? 403 : 500);
        }
    }

    public function download(Request $request, Song $song): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = Auth::user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $quality = (string) $request->input('quality', '320');
            $result = $this->songService->downloadSong($song, $user, $quality);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'download_url' => $result['download_url'],
                'expires_at' => $result['expires_at'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * GET /api/v1/songs/{song}/purchase-status
     */
    public function purchaseStatus(Request $request, Song $song): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'data' => [
                    'purchased' => false,
                    'download_access' => (bool) $song->is_free,
                    'requires_auth' => true,
                ],
            ]);
        }

        $purchased = $user->hasPurchasedSong($song);

        return response()->json([
            'data' => [
                'purchased' => $purchased,
                'download_access' => $song->isAvailableForDownload($user),
                'is_free' => (bool) $song->is_free,
            ],
        ]);
    }

    /**
     * GET /api/v1/songs/{song}/purchase/payment-status/{reference}
     */
    public function purchasePaymentStatus(Request $request, Song $song, string $reference): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $payment = Payment::query()
            ->where('user_id', $user->id)
            ->where('payable_type', Song::class)
            ->where('payable_id', $song->id)
            ->where('payment_type', 'purchase')
            ->where(function ($query) use ($reference) {
                $query->where('payment_reference', $reference)
                    ->orWhere('transaction_reference', $reference)
                    ->orWhere('provider_transaction_id', $reference)
                    ->orWhere('transaction_id', $reference);
            })
            ->latest('id')
            ->first();

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase payment not found.',
                'data' => [
                    'status' => 'not_found',
                    'purchased' => false,
                    'download_access' => false,
                    'song_id' => $song->id,
                    'reference' => $reference,
                ],
            ], 404);
        }

        if (in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)
            && ! empty($payment->provider_transaction_id)
        ) {
            try {
                $statusResult = $this->zengaPayService->checkStatus($payment->provider_transaction_id);

                if ($statusResult['success'] ?? false) {
                    $status = strtolower((string) ($statusResult['status'] ?? ''));
                    if ($status === Payment::STATUS_COMPLETED) {
                        $payment->markAsCompleted([
                            'external_transaction_id' => $payment->provider_transaction_id,
                            'provider_reference' => $payment->provider_reference ?? $payment->payment_reference,
                            'payment_data' => ['status_polled_at' => now()->toIso8601String()],
                        ]);
                        $payment->refresh();
                    } elseif ($status === Payment::STATUS_FAILED) {
                        $payment->markAsFailed(
                            $statusResult['message'] ?? 'Payment failed.',
                            ['payment_data' => ['status_polled_at' => now()->toIso8601String()]]
                        );
                        $payment->refresh();
                    } elseif ($status === Payment::STATUS_CANCELLED) {
                        $payment->markAsCancelled();
                        $payment->refresh();
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $purchased = SongPurchase::query()
            ->where('user_id', $user->id)
            ->where('song_id', $song->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $payment->status,
                'reference' => $payment->payment_reference ?? $payment->transaction_reference,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'purchased' => $purchased,
                'download_access' => $purchased,
                'song_id' => $song->id,
                'payment_id' => $payment->id,
                'completed_at' => optional($payment->completed_at)->toIso8601String(),
                'failed_at' => optional($payment->failed_at)->toIso8601String(),
                'message' => match ($payment->status) {
                    Payment::STATUS_COMPLETED => 'Payment successful. Download access is active.',
                    Payment::STATUS_FAILED => $payment->failure_reason ?: 'Payment failed.',
                    Payment::STATUS_CANCELLED => 'Payment was cancelled.',
                    Payment::STATUS_REFUNDED => 'Payment was refunded.',
                    default => 'Payment is being processed...',
                },
            ],
        ]);
    }

    /**
     * POST /api/v1/songs/{song}/purchase
     *
     * Purchases a paid song using platform credits and records
     * distribution split, artist revenue, and buyer benefits.
     */
    public function purchase(Request $request, Song $song): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($song->is_free) {
            return response()->json([
                'success' => false,
                'message' => 'This song is free and does not require purchase.',
            ], 422);
        }

        $price = (float) ($song->price ?? 0);
        if ($price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This song cannot be purchased right now.',
            ], 422);
        }

        if ($user->hasPurchasedSong($song)) {
            return response()->json([
                'success' => true,
                'message' => 'Song already purchased. Download access is active.',
                'data' => [
                    'purchase_id' => SongPurchase::where('user_id', $user->id)->where('song_id', $song->id)->value('id'),
                    'credits_deducted' => 0,
                    'credits_remaining' => (float) ($user->fresh()->creditWallet?->balance ?? 0),
                ],
            ]);
        }

        $validated = $request->validate([
            'payment_method' => 'nullable|string|in:platform_credits',
            'phone_number' => 'required_if:payment_method,zengapay|string|min:9|max:20',
        ]);

        $paymentMethod = 'platform_credits';

        try {
            if (false) { // ZengaPay direct purchase removed — all song purchases use platform credits
                $artistSharePct = max(0, min(100, (float) app(ArtistSettingsService::class)->getRevenueShare()));
                $platformSharePct = 100 - $artistSharePct;
                $artistAmount = round($price * ($artistSharePct / 100), 2);
                $platformAmount = round($price - $artistAmount, 2);

                $paymentReference = 'SONG-'.strtoupper(uniqid());

                $payment = new Payment;
                $payment->forceFill([
                    'amount' => $price,
                    'status' => Payment::STATUS_PENDING,
                ]);
                $payment->fill([
                    'user_id' => $user->id,
                    'payable_type' => Song::class,
                    'payable_id' => $song->id,
                    'song_id' => $song->id,
                    'payment_type' => 'purchase',
                    'payment_method' => 'zengapay',
                    'provider' => 'zengapay',
                    'payment_provider' => 'zengapay',
                    'phone_number' => (string) ($validated['phone_number'] ?? ''),
                    'currency' => $song->currency ?? 'UGX',
                    'description' => "Song purchase: {$song->title}",
                    'payment_reference' => $paymentReference,
                    'transaction_reference' => $paymentReference,
                    'metadata' => [
                        'song_id' => $song->id,
                        'song_title' => $song->title,
                        'artist_id' => $song->artist_id,
                        'distribution' => [
                            'artist_percentage' => $artistSharePct,
                            'platform_percentage' => $platformSharePct,
                            'artist_amount' => $artistAmount,
                            'platform_amount' => $platformAmount,
                        ],
                    ],
                ]);
                $payment->save();

                $zengaPayConfig = config('services.zengapay');

                if (empty($zengaPayConfig['api_key']) || app()->environment('local', 'testing')) {
                    $payment->forceFill([
                        'status' => Payment::STATUS_PROCESSING,
                        'provider_transaction_id' => 'DEV-'.strtoupper(\Illuminate\Support\Str::random(16)),
                    ])->save();

                    if (app()->environment('local', 'testing')) {
                        $payment->markAsCompleted([
                            'external_transaction_id' => $payment->provider_transaction_id,
                            'provider_reference' => $paymentReference,
                        ]);
                    }

                    $payment->refresh();

                    $purchaseId = SongPurchase::where('user_id', $user->id)
                        ->where('song_id', $song->id)
                        ->value('id');

                    return response()->json([
                        'success' => true,
                        'message' => $payment->status === Payment::STATUS_COMPLETED
                            ? 'Purchase complete. Download access granted.'
                            : 'Payment initiated. Please approve the payment prompt on your phone.',
                        'data' => [
                            'purchase_id' => $purchaseId,
                            'credits_deducted' => 0,
                            'credits_remaining' => (float) ($user->fresh()->creditWallet?->balance ?? 0),
                            'payment_status' => $payment->status,
                            'payment_reference' => $payment->payment_reference,
                        ],
                    ], $payment->status === Payment::STATUS_COMPLETED ? 200 : 201);
                }

                $result = $this->zengaPayService->processPayment($payment, [
                    'phone_number' => (string) $validated['phone_number'],
                ]);

                if (! ($result['success'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to initiate mobile money payment. Please try again.',
                    ], 422);
                }

                $payment->refresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Payment initiated. Please approve the payment prompt on your phone.',
                    'data' => [
                        'purchase_id' => null,
                        'credits_deducted' => 0,
                        'credits_remaining' => (float) ($user->fresh()->creditWallet?->balance ?? 0),
                        'payment_status' => $payment->status,
                        'payment_reference' => $payment->payment_reference,
                    ],
                ], 201);
            }

            $result = DB::transaction(function () use ($song, $user, $price) {
                $transaction = $user->spendCredits(
                    $price,
                    'song_purchase',
                    "Purchased song '{$song->title}'",
                    ['song_id' => $song->id]
                );

                if (! $transaction) {
                    throw new \RuntimeException('Insufficient credits to purchase this song.');
                }

                $existingPurchase = SongPurchase::where('user_id', $user->id)
                    ->where('song_id', $song->id)
                    ->first();

                if ($existingPurchase) {
                    return [
                        'already_purchased' => true,
                        'purchase' => $existingPurchase,
                        'credits_deducted' => 0,
                    ];
                }

                $purchase = SongPurchase::create([
                    'user_id' => $user->id,
                    'song_id' => $song->id,
                    'amount_paid' => $price,
                    'currency' => $song->currency ?? 'UGX',
                    'payment_method' => 'platform_credits',
                    'transaction_id' => Payment::generateTransactionId(),
                    'purchased_at' => now(),
                ]);

                $artistSharePct = max(0, min(100, (float) app(ArtistSettingsService::class)->getRevenueShare()));
                $platformSharePct = 100 - $artistSharePct;
                $artistAmount = round($price * ($artistSharePct / 100), 2);
                $platformAmount = round($price - $artistAmount, 2);

                $paymentReference = 'SONG-'.strtoupper(uniqid());

                $payment = new Payment([
                    'user_id' => $user->id,
                    'payable_type' => Song::class,
                    'payable_id' => $song->id,
                    'song_id' => $song->id,
                    'payment_type' => 'purchase',
                    'payment_method' => 'platform_credits',
                    'provider' => 'internal_credits',
                    'currency' => $song->currency ?? 'UGX',
                    'description' => "Song purchase: {$song->title}",
                    'payment_reference' => $paymentReference,
                    'transaction_reference' => $paymentReference,
                    'metadata' => [
                        'song_purchase_id' => $purchase->id,
                        'distribution' => [
                            'artist_percentage' => $artistSharePct,
                            'platform_percentage' => $platformSharePct,
                            'artist_amount' => $artistAmount,
                            'platform_amount' => $platformAmount,
                        ],
                    ],
                ]);

                $payment->forceFill([
                    'amount' => $price,
                    'status' => Payment::STATUS_COMPLETED,
                    'transaction_id' => Payment::generateTransactionId(),
                    'completed_at' => now(),
                ])->save();

                ArtistRevenue::create([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'artist_id' => $song->artist_id,
                    'revenue_type' => ArtistRevenue::TYPE_DOWNLOAD,
                    'sourceable_type' => Song::class,
                    'sourceable_id' => $song->id,
                    'song_id' => $song->id,
                    'amount_ugx' => $price,
                    'amount_usd' => 0,
                    'platform_fee' => $platformAmount,
                    'net_amount' => $artistAmount,
                    'revenue_date' => now()->toDateString(),
                    'status' => ArtistRevenue::STATUS_CONFIRMED,
                    'notes' => 'Song purchase revenue split '.$artistSharePct.'/'.$platformSharePct,
                ]);

                if ($song->artist) {
                    $song->artist->increment('earnings_balance', $artistAmount);
                    $song->artist->increment('total_revenue', $artistAmount);
                }

                $loyaltyPointsAwarded = 0;
                $loyaltyPointsBalance = null;

                try {
                    $loyaltyTx = app(LoyaltyPointsService::class)->awardPoints(
                        $user,
                        40,
                        'song_purchase',
                        $song->id,
                        Song::class,
                        "Purchased '{$song->title}'"
                    );

                    $loyaltyPointsAwarded = max(0, (int) $loyaltyTx->points);
                    $loyaltyPointsBalance = (int) $loyaltyTx->balance_after;
                } catch (\Throwable $e) {
                    report($e);
                }

                return [
                    'already_purchased' => false,
                    'purchase' => $purchase,
                    'payment' => $payment,
                    'artist_share_pct' => $artistSharePct,
                    'platform_share_pct' => $platformSharePct,
                    'artist_amount' => $artistAmount,
                    'platform_amount' => $platformAmount,
                    'credits_deducted' => $price,
                    'loyalty_points_awarded' => $loyaltyPointsAwarded,
                    'loyalty_points_balance' => $loyaltyPointsBalance,
                ];
            });

            $creditsRemaining = (float) ($user->fresh()->creditWallet?->balance ?? 0);
            $artistBalance = (float) ($song->artist?->fresh()?->earnings_balance ?? 0);

            if ($result['already_purchased']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Song already purchased. Download access is active.',
                    'data' => [
                        'purchase_id' => $result['purchase']->id,
                        'credits_deducted' => 0,
                        'credits_remaining' => $creditsRemaining,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase complete. Download access granted.',
                'data' => [
                    'purchase_id' => $result['purchase']->id,
                    'credits_deducted' => $result['credits_deducted'],
                    'credits_remaining' => $creditsRemaining,
                    'payment' => [
                        'id' => $result['payment']->id,
                        'reference' => $result['payment']->payment_reference,
                        'status' => $result['payment']->status,
                        'amount' => (float) $result['payment']->amount,
                        'currency' => $result['payment']->currency,
                    ],
                    'distribution' => [
                        'artist_name' => $song->artist?->stage_name,
                        'artist_percentage' => $result['artist_share_pct'],
                        'platform_percentage' => $result['platform_share_pct'],
                        'artist_amount' => $result['artist_amount'],
                        'platform_amount' => $result['platform_amount'],
                    ],
                    'benefits' => [
                        'download_access' => true,
                        'loyalty_points_awarded' => $result['loyalty_points_awarded'],
                        'loyalty_points_balance' => $result['loyalty_points_balance'],
                    ],
                    'artist_wallet' => [
                        'current_balance' => $artistBalance,
                    ],
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete purchase right now. Please try again.',
            ], 500);
        }
    }

    public function toggleLike(Song $song): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = Auth::user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $result = $this->songService->toggleLike($song, $user);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'is_liked' => $result['is_liked'],
                'like_count' => $result['like_count'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle like',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias for toggleLike method to match API routes
     */
    public function like(Song $song): JsonResponse
    {
        return $this->toggleLike($song);
    }

    /**
     * Check if current user has liked the song
     */
    public function isLiked(Song $song): JsonResponse
    {
        try {
            if (! Auth::check()) {
                return response()->json([
                    'success' => true,
                    'isLiked' => false,
                ]);
            }

            // Check using polymorphic likes relationship
            $isLiked = $song->likes()
                ->where('user_id', Auth::id())
                ->exists();

            return response()->json([
                'success' => true,
                'isLiked' => $isLiked,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check like status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias for recordPlay method to match API routes
     */
    public function play(Request $request, Song $song): JsonResponse
    {
        return $this->recordPlay($request, $song);
    }
}
