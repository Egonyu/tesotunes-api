<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\{Store, Product, Order, StoreReview};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

/**
 * Review Service
 * 
 * Handles store and product reviews
 */
class ReviewService
{
    /**
     * Create a product review
     */
    public function createProductReview(User $user, Product $product, array $data): StoreReview
    {
        // Check if user has purchased this product
        $hasPurchased = Order::where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->whereHas('items', fn($q) => $q->where('product_id', $product->id))
            ->exists();

        return DB::transaction(function () use ($user, $product, $data, $hasPurchased) {
            $review = StoreReview::create([
                'store_id' => $product->store_id,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'order_id' => $data['order_id'] ?? null,
                'rating' => $data['rating'],
                'title' => $data['title'] ?? null,
                'review' => $data['review'],
                'status' => config('store.reviews.auto_approve', false) ? 'approved' : 'pending',
                'is_verified_purchase' => $hasPurchased,
            ]);

            // Update product average rating
            $this->updateProductRating($product);

            // Notify seller
            app(NotificationService::class)->notifyProductReviewed($product, $user, $data['rating']);

            return $review;
        });
    }

    /**
     * Create a store review
     */
    public function createStoreReview(User $user, Store $store, array $data): StoreReview
    {
        // Check if user has ordered from this store
        $hasOrdered = Order::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->exists();

        return DB::transaction(function () use ($user, $store, $data, $hasOrdered) {
            $review = StoreReview::create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'product_id' => null,
                'order_id' => $data['order_id'] ?? null,
                'rating' => $data['rating'],
                'title' => $data['title'] ?? null,
                'review' => $data['review'],
                'status' => config('store.reviews.auto_approve', false) ? 'approved' : 'pending',
                'is_verified_purchase' => $hasOrdered,
            ]);

            // Update store average rating
            $store->updateAverageRating();

            return $review;
        });
    }

    /**
     * Update review
     */
    public function updateReview(StoreReview $review, array $data): StoreReview
    {
        $review->update([
            'rating' => $data['rating'] ?? $review->rating,
            'title' => $data['title'] ?? $review->title,
            'review' => $data['review'] ?? $review->review,
        ]);

        // Recalculate ratings
        if ($review->product_id) {
            $this->updateProductRating($review->product);
        } else {
            $review->store->updateAverageRating();
        }

        return $review->fresh();
    }

    /**
     * Delete review
     */
    public function deleteReview(StoreReview $review): bool
    {
        $productId = $review->product_id;
        $storeId = $review->store_id;

        $deleted = $review->delete();

        if ($deleted) {
            if ($productId) {
                $product = Product::find($productId);
                if ($product) {
                    $this->updateProductRating($product);
                }
            } else {
                $store = Store::find($storeId);
                if ($store) {
                    $store->updateAverageRating();
                }
            }
        }

        return $deleted;
    }

    /**
     * Approve review (admin/moderator)
     */
    public function approveReview(StoreReview $review): StoreReview
    {
        $review->update(['status' => 'approved']);
        return $review;
    }

    /**
     * Reject review (admin/moderator)
     */
    public function rejectReview(StoreReview $review, string $reason = null): StoreReview
    {
        $review->update([
            'status' => 'rejected',
            'metadata' => array_merge($review->metadata ?? [], ['rejection_reason' => $reason])
        ]);
        return $review;
    }

    /**
     * Add seller response to review
     */
    public function addSellerResponse(StoreReview $review, string $response): StoreReview
    {
        $review->update([
            'seller_response' => $response,
            'seller_response_at' => now(),
        ]);

        // Notify the reviewer
        Notification::createForUser(
            $review->user,
            'store_seller_response',
            'Seller Responded to Your Review',
            "The seller responded to your review on \"" . ($review->product->name ?? $review->store->name) . "\"",
            ['review_id' => $review->id],
            route('store.reviews.show', $review->id)
        );

        return $review;
    }

    /**
     * Mark review as helpful
     */
    public function markHelpful(StoreReview $review, User $user, bool $helpful = true): void
    {
        // Check if user already voted
        $existingVote = DB::table('review_helpful_votes')
            ->where('review_id', $review->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingVote) {
            // Update existing vote
            DB::table('review_helpful_votes')
                ->where('review_id', $review->id)
                ->where('user_id', $user->id)
                ->update([
                    'is_helpful' => $helpful,
                    'updated_at' => now(),
                ]);

            // Recalculate counts
            if ($existingVote->is_helpful != $helpful) {
                if ($helpful) {
                    $review->increment('helpful_count');
                    $review->decrement('not_helpful_count');
                } else {
                    $review->decrement('helpful_count');
                    $review->increment('not_helpful_count');
                }
            }
        } else {
            // Create new vote
            DB::table('review_helpful_votes')->insert([
                'review_id' => $review->id,
                'user_id' => $user->id,
                'is_helpful' => $helpful,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Increment count
            if ($helpful) {
                $review->increment('helpful_count');
            } else {
                $review->increment('not_helpful_count');
            }
        }
    }

    /**
     * Get reviews for product
     */
    public function getProductReviews(Product $product, array $filters = []): Collection
    {
        $query = StoreReview::where('product_id', $product->id)
            ->approved()
            ->with('user:id,name,avatar')
            ->orderByDesc('created_at');

        // Filter by rating
        if (isset($filters['rating'])) {
            $query->where('rating', $filters['rating']);
        }

        // Filter by verified purchases
        if (isset($filters['verified']) && $filters['verified']) {
            $query->verified();
        }

        // Sort
        if (isset($filters['sort'])) {
            match($filters['sort']) {
                'helpful' => $query->orderByDesc('helpful_count'),
                'recent' => $query->orderByDesc('created_at'),
                'rating_high' => $query->orderByDesc('rating'),
                'rating_low' => $query->orderBy('rating'),
                default => null,
            };
        }

        return $query->get();
    }

    /**
     * Get review statistics for product
     */
    public function getProductReviewStats(Product $product): array
    {
        $reviews = StoreReview::where('product_id', $product->id)
            ->approved()
            ->get();

        $ratingBreakdown = $reviews->groupBy('rating')->map->count()->toArray();

        return [
            'total_reviews' => $reviews->count(),
            'average_rating' => round($reviews->avg('rating'), 2),
            'rating_breakdown' => [
                5 => $ratingBreakdown[5] ?? 0,
                4 => $ratingBreakdown[4] ?? 0,
                3 => $ratingBreakdown[3] ?? 0,
                2 => $ratingBreakdown[2] ?? 0,
                1 => $ratingBreakdown[1] ?? 0,
            ],
            'verified_purchases' => $reviews->where('is_verified_purchase', true)->count(),
            'with_photos' => 0, // TODO: When image upload is added
            'with_seller_response' => $reviews->whereNotNull('seller_response')->count(),
        ];
    }

    /**
     * Update product average rating
     */
    protected function updateProductRating(Product $product): void
    {
        $average = StoreReview::where('product_id', $product->id)
            ->approved()
            ->avg('rating');

        $count = StoreReview::where('product_id', $product->id)
            ->approved()
            ->count();

        $product->update([
            'average_rating' => round($average ?? 0, 2),
            'reviews_count' => $count,
        ]);
    }

    /**
     * Check if user can review product
     */
    public function canReviewProduct(User $user, Product $product): array
    {
        // Check if already reviewed
        $existingReview = StoreReview::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existingReview) {
            return [
                'can_review' => false,
                'reason' => 'You have already reviewed this product',
            ];
        }

        // Check if purchased
        $hasPurchased = Order::where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->where('status', 'delivered')
            ->whereHas('items', fn($q) => $q->where('product_id', $product->id))
            ->exists();

        if (!$hasPurchased && config('store.reviews.require_purchase', false)) {
            return [
                'can_review' => false,
                'reason' => 'You must purchase this product before reviewing',
            ];
        }

        return [
            'can_review' => true,
            'is_verified' => $hasPurchased,
        ];
    }
}
