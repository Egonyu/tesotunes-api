<?php

namespace App\Observers;

use App\Models\Event;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

/**
 * Announces posted promotion requests on the Edula feed so promoters
 * discover work from the timeline, not just the promotion requests page.
 */
class PromotionRequestObserver
{
    public function created(PromotionRequest $promotionRequest): void
    {
        if ($promotionRequest->status !== PromotionRequest::STATUS_OPEN) {
            return;
        }

        try {
            $creator = $promotionRequest->creator;

            if (! $creator) {
                return;
            }

            ActivityService::log(
                actor: $creator,
                action: 'posted_opportunity',
                subject: $promotionRequest,
                metadata: [
                    'title' => $promotionRequest->title,
                    'budget_max_ugx' => (float) $promotionRequest->budget_max_ugx,
                    'max_awards' => (int) $promotionRequest->max_awards,
                ],
            );

            $promotable = $promotionRequest->promotable;
            $subjectLabel = $promotable?->title ?? $promotable?->name ?? null;
            $kind = $promotionRequest->promotable_type === Event::class ? 'event' : 'music';

            FeedItemService::create([
                'type' => 'opportunity_posted',
                'module' => 'promotions',
                'title' => ($creator->display_name ?? $creator->name ?? 'An artist')
                    .' is looking for promoters'
                    .($subjectLabel ? " — {$subjectLabel}" : ''),
                'body' => $promotionRequest->brief ? substr(strip_tags($promotionRequest->brief), 0, 200) : null,
                'actor_id' => $creator->id,
                'actor_type' => 'user',
                'actor_name' => $creator->display_name ?? $creator->name,
                'actor_avatar_url' => $creator->avatar_url ?? null,
                'subject_type' => PromotionRequest::class,
                'subject_id' => $promotionRequest->id,
                'tags' => array_values(array_filter([
                    'promotions',
                    $kind,
                    ...(array) ($promotionRequest->target_platforms ?? []),
                ])),
                'actions' => [
                    ['type' => 'view', 'label' => 'Apply now', 'url' => "/promotions/requests/{$promotionRequest->uuid}"],
                ],
                'extras' => [
                    'budget_min_ugx' => (float) $promotionRequest->budget_min_ugx,
                    'budget_max_ugx' => (float) $promotionRequest->budget_max_ugx,
                    'budget_credits' => (int) $promotionRequest->budget_credits,
                    'max_awards' => (int) $promotionRequest->max_awards,
                    'deadline_at' => $promotionRequest->deadline_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create feed entry for promotion promotion request', [
                'promotion_request_id' => $promotionRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
