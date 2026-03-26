<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPromotionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPromotionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = $request->string('status')->toString();
        $search = trim((string) $request->input('search', ''));

        $query = EventPromotionRequest::query()
            ->with(['event', 'requestedBy', 'moderatedBy'])
            ->when($status !== '', function ($builder) use ($status) {
                $builder->where('status', $status);
            })
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('promotion_title', 'like', "%{$search}%")
                        ->orWhere('promotion_slug', 'like', "%{$search}%")
                        ->orWhereHas('event', function ($eventQuery) use ($search) {
                            $eventQuery->where('title', 'like', "%{$search}%");
                        })
                        ->orWhereHas('requestedBy', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('requested_at');

        $promotions = $query->paginate($perPage);

        return response()->json([
            'data' => collect($promotions->items())->map(fn (EventPromotionRequest $promotionRequest) => $this->serializePromotionListItem($promotionRequest))->values(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'total' => $promotions->total(),
                'per_page' => $promotions->perPage(),
                'last_page' => $promotions->lastPage(),
                'from' => $promotions->firstItem(),
                'to' => $promotions->lastItem(),
            ],
        ]);
    }

    public function approve(Request $request, EventPromotionRequest $promotion): JsonResponse
    {
        $promotion->approve($request->user());

        return response()->json([
            'success' => true,
            'status' => $promotion->status,
            'data' => $this->serializePromotionListItem($promotion->loadMissing(['event', 'requestedBy', 'moderatedBy'])),
        ]);
    }

    public function reject(Request $request, EventPromotionRequest $promotion): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $promotion->reject($request->user(), $validated['reason']);

        return response()->json([
            'success' => true,
            'status' => $promotion->status,
            'data' => $this->serializePromotionListItem($promotion->loadMissing(['event', 'requestedBy', 'moderatedBy'])),
        ]);
    }

    public function analytics(): JsonResponse
    {
        $baseQuery = EventPromotionRequest::query();
        $total = (clone $baseQuery)->count();
        $active = (clone $baseQuery)->where('status', EventPromotionRequest::STATUS_ACTIVE)->count();
        $rejected = (clone $baseQuery)->where('status', EventPromotionRequest::STATUS_REJECTED)->count();
        $pending = (clone $baseQuery)->where('status', EventPromotionRequest::STATUS_PENDING)->count();
        $gmvUgx = (float) (clone $baseQuery)->sum('price_ugx');
        $gmvCredits = (float) (clone $baseQuery)->sum('price_credits');

        $topPromoters = EventPromotionRequest::query()
            ->with('requestedBy')
            ->selectRaw('requested_by_user_id, COUNT(*) as aggregate_count')
            ->groupBy('requested_by_user_id')
            ->orderByDesc('aggregate_count')
            ->limit(5)
            ->get()
            ->map(function (EventPromotionRequest $promotionRequest) {
                $user = $promotionRequest->requestedBy;

                return [
                    'id' => $user?->id,
                    'name' => $user?->name ?? 'Unknown Organizer',
                    'username' => $user?->username ?? 'unknown',
                    'avatar_url' => null,
                    'is_verified' => (bool) ($user?->email_verified_at),
                    'follower_count' => 0,
                ];
            })
            ->filter(fn (array $user) => $user['id'] !== null)
            ->values();

        $topTypes = EventPromotionRequest::query()
            ->selectRaw('COALESCE(promotion_type, ?) as promotion_type, COUNT(*) as aggregate_count, SUM(price_ugx) as ugx_revenue', ['event_boost'])
            ->groupBy('promotion_type')
            ->orderByDesc('aggregate_count')
            ->limit(5)
            ->get()
            ->map(fn (EventPromotionRequest $promotionRequest) => [
                'type' => $promotionRequest->promotion_type ?: 'ticket_giveaway',
                'count' => (int) ($promotionRequest->aggregate_count ?? 0),
                'revenue' => (float) ($promotionRequest->ugx_revenue ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => [
                'total_promotions' => $total,
                'active_promotions' => $active,
                'total_orders' => $total,
                'total_gmv_credits' => $gmvCredits,
                'total_gmv_ugx' => $gmvUgx,
                'platform_revenue_ugx' => 0,
                'top_promoters' => $topPromoters,
                'top_promotion_types' => $topTypes,
                'average_order_value' => $total > 0 ? round($gmvUgx / $total, 2) : 0,
                'dispute_rate' => $total > 0 ? round($rejected / $total, 4) : 0,
                'pending_promotions' => $pending,
            ],
        ]);
    }

    public function disputes(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'total' => 0,
                'per_page' => $perPage,
                'last_page' => 1,
                'from' => null,
                'to' => null,
            ],
        ]);
    }

    public function resolveDispute(): JsonResponse
    {
        return response()->json([
            'success' => true,
        ]);
    }

    private function serializePromotionListItem(EventPromotionRequest $promotionRequest): array
    {
        $event = $promotionRequest->event;
        $organizer = $promotionRequest->requestedBy;

        return [
            'id' => $promotionRequest->id,
            'slug' => $promotionRequest->promotion_slug ?: 'event-promotion-'.$promotionRequest->id,
            'title' => $promotionRequest->promotion_title,
            'short_description' => $promotionRequest->request_notes ?: 'Event promotion request awaiting Tesotunes moderation.',
            'type' => $this->mapPromotionType($promotionRequest->promotion_type),
            'platform' => $this->mapPromotionPlatform($promotionRequest->promotion_platform),
            'price_credits' => (float) $promotionRequest->price_credits,
            'price_ugx' => (float) $promotionRequest->price_ugx,
            'accepts_credits' => (float) $promotionRequest->price_credits > 0,
            'accepts_ugx' => (float) $promotionRequest->price_ugx > 0,
            'accepts_hybrid' => (float) $promotionRequest->price_credits > 0 && (float) $promotionRequest->price_ugx > 0,
            'estimated_reach' => 0,
            'delivery_days_min' => 1,
            'delivery_days_max' => 7,
            'rating_average' => 0,
            'rating_count' => 0,
            'total_orders' => 0,
            'completed_orders' => 0,
            'is_featured' => false,
            'is_top_rated' => false,
            'featured_image_url' => $promotionRequest->featured_image_url,
            'status' => $promotionRequest->status,
            'created_at' => optional($promotionRequest->requested_at)->toIso8601String() ?? optional($promotionRequest->created_at)->toIso8601String(),
            'promoter' => [
                'id' => $organizer?->id ?? 0,
                'name' => $organizer?->name ?? 'Unknown Organizer',
                'username' => $organizer?->username ?? 'unknown',
                'avatar_url' => null,
                'is_verified' => (bool) ($organizer?->email_verified_at),
                'follower_count' => 0,
            ],
            'event' => $event ? [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
            ] : null,
            'moderation_notes' => $promotionRequest->moderation_notes,
        ];
    }

    private function mapPromotionType(?string $type): string
    {
        return match ($type) {
            'live_stream_promotion',
            'radio_mention',
            'dj_shoutout',
            'ticket_giveaway',
            'content_creation',
            'playlist_inclusion',
            'collaboration_offer' => $type,
            default => 'social_media_mention',
        };
    }

    private function mapPromotionPlatform(?string $platform): string
    {
        return match ($platform) {
            'instagram',
            'tiktok',
            'facebook',
            'youtube',
            'twitter',
            'spotify',
            'apple_music',
            'radio',
            'club',
            'event',
            'blog',
            'podcast' => $platform,
            default => 'other',
        };
    }
}
