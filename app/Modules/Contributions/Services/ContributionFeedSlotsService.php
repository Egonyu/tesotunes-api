<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionTask;
use Illuminate\Support\Collection;

/**
 * Weaves "Earn" task cards into the Edula feed — one-tap translation prompts
 * labeled as paid micro-tasks, mirroring the sponsored-slot mechanism. No-ops
 * entirely unless the module and feed cards are enabled, so it's safe to call
 * from the core feed controller.
 */
class ContributionFeedSlotsService
{
    public function __construct(private readonly DailyChallengeService $dailyChallenge) {}

    /**
     * @param  Collection<int, array>  $pageItems
     * @return Collection<int, array>
     */
    public function injectInto(Collection $pageItems, int $page = 1, ?User $user = null): Collection
    {
        if (! config('contributions.enabled', false) || ! config('contributions.feed.enabled', true)) {
            return $pageItems;
        }

        if ($pageItems->isEmpty()) {
            return $pageItems;
        }

        $every = max(2, (int) config('contributions.feed.every', 6));
        $maxPerPage = max(1, (int) config('contributions.feed.max_per_page', 2));

        $cards = $this->earnCards($user, $maxPerPage);
        if ($cards->isEmpty()) {
            return $pageItems;
        }

        $result = collect();
        $injected = 0;

        foreach ($pageItems as $index => $item) {
            $result->push($item);

            if (($index + 1) % $every === 0 && $injected < $cards->count()) {
                $result->push($cards[$injected]);
                $injected++;
            }
        }

        return $result->values();
    }

    /**
     * Open translate tasks the user hasn't answered, daily challenge first.
     *
     * @return Collection<int, array>
     */
    public function earnCards(?User $user, int $limit): Collection
    {
        // Earn cards are an authenticated, one-answer-per-task mechanic — guests
        // can't submit, so there's nothing to show them.
        if (! $user) {
            return collect();
        }

        // Open translate tasks this user has NOT already answered. Excluding
        // submitted tasks is what stops the feed re-serving work they've done.
        $open = ContributionTask::query()
            ->where('type', ContributionTask::TYPE_TRANSLATE)
            ->where('status', ContributionTask::STATUS_OPEN)
            ->where('is_gold', false)
            ->whereDoesntHave('submissions', fn ($s) => $s->where('user_id', $user->id));

        // Surface the daily challenge first — but only if they haven't answered
        // it yet (the same exclusion the rest of the pool gets).
        $challenge = $this->dailyChallenge->today();
        $challengeAvailable = $challenge
            && ! $challenge->submissions()->where('user_id', $user->id)->exists();

        $tasks = collect();
        if ($challengeAvailable) {
            $tasks->push($challenge);
        }

        $more = $open
            ->when($challenge, fn ($q) => $q->where('id', '!=', $challenge->id))
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $tasks->merge($more)->take($limit)->map(fn (ContributionTask $task) => $this->toCard($task))->values();
    }

    private function toCard(ContributionTask $task): array
    {
        $isChallenge = $task->register === 'daily_challenge';

        return [
            'id' => -2000000 - $task->id, // distinct negative range from sponsored cards
            'uuid' => 'earn-'.$task->uuid,
            'source' => 'contribution_task',
            'feed_type' => 'contribution_task',
            'module' => 'platform',
            'is_earn' => true,
            'is_daily_challenge' => $isChallenge,
            'author' => [
                'id' => 0,
                'name' => 'TesoTunes',
                'username' => 'tesotunes',
                'avatar_url' => '',
                'is_verified' => true,
            ],
            'title' => $isChallenge ? 'Daily challenge — earn credits' : 'Help translate — earn credits',
            'content' => $isChallenge
                ? 'How would you say this in Ateso?'
                : 'How would you translate this line?',
            'task' => [
                'uuid' => $task->uuid,
                'prompt_text' => $task->prompt_text,
                'source_lang' => $task->source_lang,
                'target_lang' => $task->target_lang,
                'register' => $task->register,
                'reward_credits' => (int) config('contributions.rewards.per_pair_ugx', 200),
            ],
            'visibility' => 'public',
            'created_at' => now()->toIso8601String(),
            'likes_count' => 0,
            'comments_count' => 0,
            'reposts_count' => 0,
            'views_count' => 0,
            'is_liked' => false,
            'is_reposted' => false,
            'is_bookmarked' => false,
        ];
    }
}
