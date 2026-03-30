<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    public function index(Request $request, string $reviewableType, int $reviewableId): JsonResponse
    {
        if (! Schema::hasTable('reviews')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $this->getPerPage($request),
                    'total' => 0,
                ],
            ]);
        }

        $modelClass = Review::resolveReviewableClass($reviewableType);

        if (! $modelClass || ! class_exists($modelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reviewable type',
            ], 400);
        }

        $modelClass::findOrFail($reviewableId);

        $reviews = Review::query()
            ->where('reviewable_type', $modelClass)
            ->where('reviewable_id', $reviewableId)
            ->approved()
            ->with('user')
            ->latest()
            ->paginate($this->getPerPage($request));

        $reviews->getCollection()->transform(function (Review $review) {
            return $this->serializeReview($review, auth()->user());
        });

        return response()->json([
            'success' => true,
            'data' => $reviews,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! Schema::hasTable('reviews')) {
            return response()->json([
                'success' => false,
                'message' => 'Reviews are not available in this environment yet.',
            ], 503);
        }

        $validator = Validator::make($request->all(), [
            'reviewable_type' => 'required|string',
            'reviewable_id' => 'required|integer',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:120',
            'content' => 'required|string|max:2000',
            'order_id' => 'nullable|integer',
            'is_verified_purchase' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $modelClass = Review::resolveReviewableClass((string) $request->input('reviewable_type'));

        if (! $modelClass || ! class_exists($modelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reviewable type',
            ], 400);
        }

        $reviewable = $modelClass::findOrFail((int) $request->input('reviewable_id'));
        $user = $request->user();

        $review = Review::updateOrCreate(
            [
                'user_id' => $user->id,
                'reviewable_type' => $modelClass,
                'reviewable_id' => $reviewable->getKey(),
            ],
            [
                'order_id' => $request->input('order_id'),
                'rating' => (int) $request->input('rating'),
                'title' => $request->input('title'),
                'content' => (string) $request->input('content'),
                'status' => Review::STATUS_APPROVED,
                'is_verified_purchase' => (bool) $request->boolean('is_verified_purchase'),
                'metadata' => $request->input('metadata', []),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Review saved successfully',
            'data' => $this->serializeReview($review->fresh('user'), $user),
        ], 201);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        if (! $review->canBeEditedBy($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to edit this review',
            ], 403);
        }

        $validated = $request->validate([
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'title' => 'nullable|string|max:120',
            'content' => 'sometimes|required|string|max:2000',
            'metadata' => 'nullable|array',
        ]);

        $review->update([
            'rating' => $validated['rating'] ?? $review->rating,
            'title' => $validated['title'] ?? $review->title,
            'content' => $validated['content'] ?? $review->content,
            'metadata' => $validated['metadata'] ?? $review->metadata,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review updated successfully',
            'data' => $this->serializeReview($review->fresh('user'), $request->user()),
        ]);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        if (! $review->canBeDeletedBy($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this review',
            ], 403);
        }

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted successfully',
        ]);
    }

    public function markHelpful(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'helpful' => 'required|boolean',
        ]);

        $review->markHelpful($request->user(), (bool) $validated['helpful']);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback.',
            'data' => $this->serializeReview($review->fresh('user'), $request->user()),
        ]);
    }

    public function eligibility(Request $request, string $reviewableType, int $reviewableId): JsonResponse
    {
        $modelClass = Review::resolveReviewableClass($reviewableType);

        if (! $modelClass || ! class_exists($modelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reviewable type',
            ], 400);
        }

        $reviewable = $modelClass::findOrFail($reviewableId);
        $user = $request->user();

        $existingReview = Review::query()
            ->where('user_id', $user->id)
            ->where('reviewable_type', $modelClass)
            ->where('reviewable_id', $reviewableId)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => true,
                'data' => [
                    'can_review' => false,
                    'reason' => 'You have already reviewed this item.',
                    'is_verified' => (bool) $existingReview->is_verified_purchase,
                ],
            ]);
        }

        $isVerified = false;
        $reason = null;

        if ($reviewable instanceof Product) {
            $isVerified = Order::where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->where('status', 'delivered')
                ->whereHas('items', fn ($q) => $q->where('product_id', $reviewable->id))
                ->exists();

            if (! $isVerified && config('store.reviews.require_purchase', false)) {
                $reason = 'You must purchase this product before reviewing.';
            }
        } elseif ($reviewable instanceof Store) {
            $isVerified = Order::where('user_id', $user->id)
                ->where('store_id', $reviewable->id)
                ->where('payment_status', 'paid')
                ->exists();

            if (! $isVerified && config('store.reviews.require_purchase', false)) {
                $reason = 'You must purchase from this store before reviewing.';
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'can_review' => $reason === null,
                'reason' => $reason,
                'is_verified' => $isVerified,
            ],
        ]);
    }

    private function serializeReview(Review $review, $viewer = null): array
    {
        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'content' => $review->content,
            'status' => $review->status,
            'is_verified_purchase' => (bool) $review->is_verified_purchase,
            'helpful_count' => (int) ($review->helpful_count ?? 0),
            'not_helpful_count' => (int) ($review->not_helpful_count ?? 0),
            'seller_response' => $review->seller_response,
            'seller_response_at' => optional($review->seller_response_at)->toIso8601String(),
            'metadata' => $review->metadata ?? [],
            'is_helpful_marked' => $viewer ? $review->isHelpfulMarkedBy($viewer) : null,
            'can_edit' => $review->canBeEditedBy($viewer),
            'can_delete' => $review->canBeDeletedBy($viewer),
            'user' => $review->user ? [
                'id' => $review->user->id,
                'name' => $review->user->name,
                'username' => $review->user->username,
                'avatar_url' => $review->user->avatar_url ?? $review->user->avatar ?? null,
                'is_verified' => (bool) ($review->user->is_verified ?? false),
            ] : null,
            'created_at' => optional($review->created_at)->toIso8601String(),
            'updated_at' => optional($review->updated_at)->toIso8601String(),
        ];
    }
}
