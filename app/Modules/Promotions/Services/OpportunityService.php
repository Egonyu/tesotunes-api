<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Promotions\Notifications\ApplicationAwardedNotification;
use App\Modules\Promotions\Notifications\ApplicationRejectedNotification;
use App\Modules\Promotions\Notifications\ApplicationSubmittedNotification;
use App\Modules\Promotions\Notifications\OpportunityPostedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OpportunityService
{
    /**
     * Post a new promotion opportunity for a piece of content.
     *
     * @param  Model  $promotable  Song, Album, or Event
     * @param  array<string, mixed>  $data
     */
    public function createForContent(User $creator, Model $promotable, array $data): PromotionOpportunity
    {
        return DB::transaction(function () use ($creator, $promotable, $data): PromotionOpportunity {
            $opportunity = PromotionOpportunity::create([
                'created_by_user_id' => $creator->id,
                'promotable_type' => $promotable->getMorphClass(),
                'promotable_id' => $promotable->getKey(),
                'title' => $data['title'],
                'brief' => $data['brief'] ?? null,
                'target_platforms' => $data['target_platforms'] ?? null,
                'target_audience_niches' => $data['target_audience_niches'] ?? null,
                'target_regions' => $data['target_regions'] ?? null,
                'budget_min_ugx' => $data['budget_min_ugx'] ?? 0,
                'budget_max_ugx' => $data['budget_max_ugx'] ?? 0,
                'budget_credits' => $data['budget_credits'] ?? 0,
                'deadline_at' => $data['deadline_at'] ?? null,
                'deliverables' => $data['deliverables'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Notify matching promoters asynchronously
            $creator->notify(new OpportunityPostedNotification($opportunity));

            return $opportunity;
        });
    }

    /**
     * Submit a promoter's application to an opportunity.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(PromotionOpportunity $opportunity, User $applicant, array $data): PromotionApplication
    {
        $profile = $applicant->promoterProfile;

        if (! $profile) {
            throw new \RuntimeException('User must complete promoter onboarding before applying.');
        }

        if (! in_array($opportunity->status, [PromotionOpportunity::STATUS_OPEN, PromotionOpportunity::STATUS_REVIEWING])) {
            throw new \RuntimeException('This opportunity is no longer accepting applications.');
        }

        $existing = PromotionApplication::where('opportunity_id', $opportunity->id)
            ->where('promoter_profile_id', $profile->id)
            ->exists();

        if ($existing) {
            throw new \RuntimeException('You have already applied to this opportunity.');
        }

        return DB::transaction(function () use ($opportunity, $applicant, $profile, $data): PromotionApplication {
            $application = PromotionApplication::create([
                'opportunity_id' => $opportunity->id,
                'promoter_profile_id' => $profile->id,
                'applicant_user_id' => $applicant->id,
                'proposed_price_ugx' => $data['proposed_price_ugx'] ?? 0,
                'proposed_price_credits' => $data['proposed_price_credits'] ?? 0,
                'pitch_message' => $data['pitch_message'] ?? null,
                'proposed_deliverables' => $data['proposed_deliverables'] ?? null,
                'proposed_timeline_days' => $data['proposed_timeline_days'] ?? null,
            ]);

            // Notify opportunity creator
            $opportunity->creator?->notify(new ApplicationSubmittedNotification($application));

            return $application;
        });
    }

    /**
     * Award an opportunity to a specific application.
     */
    public function award(PromotionOpportunity $opportunity, PromotionApplication $application): bool
    {
        if ($application->opportunity_id !== $opportunity->id) {
            throw new \InvalidArgumentException('Application does not belong to this opportunity.');
        }

        return DB::transaction(function () use ($opportunity, $application): bool {
            $awarded = $opportunity->award($application);

            if (! $awarded) {
                return false;
            }

            $application->transitionTo(PromotionApplication::STATUS_AWARDED);

            // Reject all other applications automatically
            PromotionApplication::where('opportunity_id', $opportunity->id)
                ->where('id', '!=', $application->id)
                ->whereIn('status', [PromotionApplication::STATUS_SUBMITTED, PromotionApplication::STATUS_SHORTLISTED])
                ->each(function (PromotionApplication $other): void {
                    $other->reject('Another application was selected.');
                    $other->applicant?->notify(new ApplicationRejectedNotification($other));
                });

            // Notify winner
            $application->applicant?->notify(new ApplicationAwardedNotification($application));

            return true;
        });
    }

    /**
     * Shortlist an application for the artist's review.
     */
    public function shortlist(PromotionApplication $application): bool
    {
        return $application->transitionTo(PromotionApplication::STATUS_SHORTLISTED);
    }

    /**
     * Withdraw an application (by the applicant themselves).
     */
    public function withdrawApplication(PromotionApplication $application, User $user): bool
    {
        if ($application->applicant_user_id !== $user->id) {
            throw new \InvalidArgumentException('You can only withdraw your own application.');
        }

        return $application->withdraw();
    }
}
