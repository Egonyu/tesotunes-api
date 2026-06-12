<?php

namespace App\Observers;

use App\Models\Event;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

/**
 * Announces posted promotion opportunities on the Edula feed so promoters
 * discover work from the timeline, not just the opportunities page.
 */
class PromotionOpportunityObserver
{
    public function created(PromotionOpportunity $opportunity): void
    {
        if ($opportunity->status !== PromotionOpportunity::STATUS_OPEN) {
            return;
        }

        try {
            $creator = $opportunity->creator;

            if (! $creator) {
                return;
            }

            ActivityService::log(
                actor: $creator,
                action: 'posted_opportunity',
                subject: $opportunity,
                metadata: [
                    'title' => $opportunity->title,
                    'budget_max_ugx' => (float) $opportunity->budget_max_ugx,
                    'max_awards' => (int) $opportunity->max_awards,
                ],
            );

            $promotable = $opportunity->promotable;
            $subjectLabel = $promotable?->title ?? $promotable?->name ?? null;
            $kind = $opportunity->promotable_type === Event::class ? 'event' : 'music';

            FeedItemService::create([
                'type' => 'opportunity_posted',
                'module' => 'promotions',
                'title' => ($creator->display_name ?? $creator->name ?? 'An artist')
                    .' is looking for promoters'
                    .($subjectLabel ? " — {$subjectLabel}" : ''),
                'body' => $opportunity->brief ? substr(strip_tags($opportunity->brief), 0, 200) : null,
                'actor_id' => $creator->id,
                'actor_type' => 'user',
                'actor_name' => $creator->display_name ?? $creator->name,
                'actor_avatar_url' => $creator->avatar_url ?? null,
                'subject_type' => PromotionOpportunity::class,
                'subject_id' => $opportunity->id,
                'tags' => array_values(array_filter([
                    'promotions',
                    $kind,
                    ...(array) ($opportunity->target_platforms ?? []),
                ])),
                'actions' => [
                    ['type' => 'view', 'label' => 'Apply now', 'url' => "/promotions/opportunities/{$opportunity->uuid}"],
                ],
                'extras' => [
                    'budget_min_ugx' => (float) $opportunity->budget_min_ugx,
                    'budget_max_ugx' => (float) $opportunity->budget_max_ugx,
                    'budget_credits' => (int) $opportunity->budget_credits,
                    'max_awards' => (int) $opportunity->max_awards,
                    'deadline_at' => $opportunity->deadline_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create feed entry for promotion opportunity', [
                'opportunity_id' => $opportunity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
