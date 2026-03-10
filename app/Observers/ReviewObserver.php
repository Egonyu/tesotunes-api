<?php

namespace App\Observers;

use App\Modules\Store\Models\Review;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class ReviewObserver
{
    public function created(Review $review): void
    {
        try {
            $user = $review->user;
            if (! $user) {
                return;
            }

            ActivityService::log(
                actor: $user,
                action: 'reviewed_product',
                subject: $review,
                metadata: [
                    'rating' => $review->rating,
                    'store_id' => $review->store_id,
                ]
            );

            FeedItemService::create([
                'type' => 'product_reviewed',
                'module' => 'store',
                'title' => ($user->name ?? 'Someone').' left a '.$review->rating.'-star review',
                'body' => $review->comment ? substr($review->comment, 0, 200) : null,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'actor_name' => $user->name,
                'actor_avatar_url' => $user->avatar_url ?? null,
                'subject_type' => Review::class,
                'subject_id' => $review->id,
                'visibility' => 'members',
                'extras' => [
                    'rating' => $review->rating,
                    'store_id' => $review->store_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ReviewObserver: Failed to create feed item', ['review_id' => $review->id, 'error' => $e->getMessage()]);
        }
    }
}
