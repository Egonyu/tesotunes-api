<?php

namespace App\Modules\Contributions\Services;

use App\Modules\Contributions\Models\ContributionTask;
use Illuminate\Support\Carbon;

/**
 * The themed prompt of the day — market talk, family, weather, proverbs —
 * aimed at registers the corpus is thin on. One challenge task per day,
 * picked deterministically from the configured rotation.
 */
class DailyChallengeService
{
    /**
     * Today's challenge task, if one has been published.
     */
    public function today(?Carbon $date = null): ?ContributionTask
    {
        $key = ($date ?? now())->toDateString();

        return ContributionTask::query()
            ->where('register', 'daily_challenge')
            ->where('metadata->challenge_date', $key)
            ->first();
    }

    /**
     * Publish today's challenge (idempotent). Picks from the configured
     * rotation by day-of-year so the theme cycles predictably.
     */
    public function publishToday(?Carbon $date = null): ?ContributionTask
    {
        $date ??= now();

        if ($existing = $this->today($date)) {
            return $existing;
        }

        $rotation = (array) config('contributions.daily_challenges', []);
        if ($rotation === []) {
            return null;
        }

        $theme = $rotation[$date->dayOfYear % count($rotation)];

        return ContributionTask::create([
            'type' => ContributionTask::TYPE_TRANSLATE,
            'source_lang' => config('contributions.languages.target'),
            'target_lang' => config('contributions.languages.source'),
            'region' => config('contributions.default_region'),
            'register' => 'daily_challenge',
            'prompt_text' => $theme['prompt'],
            'redundancy_target' => (int) config('contributions.redundancy_target', 3),
            'status' => ContributionTask::STATUS_OPEN,
            'metadata' => [
                'challenge_date' => $date->toDateString(),
                'theme' => $theme['register'] ?? null,
            ],
        ]);
    }
}
