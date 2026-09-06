<?php

namespace App\Services\Dashboard;

use App\Models\Activity;
use App\Models\Artist;
use App\Models\CreditTransaction;
use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Services\Commerce\SettlementService;
use App\Services\Credits\RewardRuleService;
use App\Services\CreditService;
use App\Services\ProfileCompletionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Assembles the unified, capability-aware account dashboard for any user — the
 * non-artist counterpart to the artist studio. Pulls from existing sources
 * (wallet, the settlement ledger, play history, contributor profile, capability
 * grants); sections are present only when relevant to the user.
 *
 * One request serves the whole screen, so the client never has to fan out and
 * one slow section cannot blank the page.
 *
 * @phpstan-type Money array{ugx: float, credits: int}
 */
class DashboardService
{
    /**
     * Credit sources recorded as "earned" that the platform did not award for
     * activity — money the user converted themselves, or credits another
     * member sent them. Telling someone they earned credits they bought is
     * worse than telling them nothing.
     *
     * @var list<string>
     */
    private const NOT_EARNED_SOURCES = ['wallet_purchase', 'transfer_in'];

    /**
     * Ranking used for the obligations queue, so every source of an action
     * lands in the same order rather than in the order it was appended.
     *
     * @var array<string, int>
     */
    private const IMPORTANCE_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public function __construct(
        private readonly SettlementService $settlements,
        private readonly ProfileCompletionService $profileCompletion,
        private readonly RewardRuleService $rewardRules,
        private readonly CreditService $credits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $balances = $this->settlements->balances($user);
        $capabilities = $user->capabilities()
            ->where('status', 'granted')
            ->pluck('capability')
            ->all();

        $contributions = $this->contributions($user);

        return [
            'wallet' => $this->wallet($user),
            'earnings' => [
                // Money owed to the user across every vertical (music, store,
                // events, promotions, contributions) from the unified ledger.
                'pending' => $balances['pending'],
                'available' => $balances['cleared'],
                'paid_out' => $balances['paid_out'],
            ],
            'daily_bonus' => $this->dailyBonus($user),
            'listening' => $this->listening($user),
            'profile' => $this->profile($user),
            'next_actions' => $this->nextActions($user, $contributions),
            'capabilities' => $capabilities,
            'contributions' => $contributions,
            'artist' => $this->artist($user),
            'recent_activity' => $this->recentActivity($user),
        ];
    }

    /**
     * @return array{ugx_balance: float, credits_balance: int, credits_earned_today: int}
     */
    private function wallet(User $user): array
    {
        $earnedToday = (int) $user->creditTransactions()
            ->whereIn('type', [CreditTransaction::TYPE_EARNED, CreditTransaction::TYPE_BONUS])
            ->whereNotIn('source', self::NOT_EARNED_SOURCES)
            ->whereDate('created_at', today())
            ->sum('amount');

        return [
            'ugx_balance' => (float) ($user->ugx_balance ?? 0),
            'credits_balance' => (int) $user->credit_balance,
            'credits_earned_today' => $earnedToday,
        ];
    }

    /**
     * The daily login bonus, as the rules engine sees it — so the client can
     * offer the claim only when it would actually succeed, rather than inviting
     * a tap that comes back 422.
     *
     * The rate row owns the reward and the cooldown, so an operator editing it
     * changes the dashboard too.
     *
     * @return array{available: bool, credits: float, available_in_minutes: int, streak_days: int}|null
     */
    private function dailyBonus(User $user): ?array
    {
        $rate = $this->rewardRules->rateFor('daily_login');

        if (! $rate) {
            return null;
        }

        $waitMinutes = $rate->cooldown_minutes
            ? $this->rewardRules->cooldownRemaining($user, 'daily_login', $rate->cooldown_minutes)
            : 0;

        return [
            'available' => $waitMinutes === 0,
            'credits' => (float) $rate->credits_per_action,
            'available_in_minutes' => $waitMinutes,
            'streak_days' => $this->credits->getLoginStreak($user),
        ];
    }

    /**
     * All-time leads, because a 30-day window says nothing about an account
     * that plays a song a month. The previous window rides along so the client
     * can show movement rather than a bare count.
     *
     * @return array<string, mixed>
     */
    private function listening(User $user): array
    {
        $windowStart = now()->subDays(30);
        $previousStart = now()->subDays(60);

        $recent = $user->playHistory()->where('played_at', '>=', $windowStart);

        return [
            'plays_total' => $user->playHistory()->count(),
            'plays_30d' => (clone $recent)->count(),
            'plays_previous_30d' => $user->playHistory()
                ->where('played_at', '>=', $previousStart)
                ->where('played_at', '<', $windowStart)
                ->count(),
            'minutes_30d' => (int) round(
                ((clone $recent)->sum('duration_played_seconds') ?: 0) / 60
            ),
            'completed_30d' => (clone $recent)->where('completed', true)->count(),
            'last_played_at' => $this->asIso($user->playHistory()->max('played_at')),
        ];
    }

    /**
     * @return array{completion_percentage: int, phone_verified: bool, email_verified: bool}
     */
    private function profile(User $user): array
    {
        return [
            'completion_percentage' => (int) ($user->profile_completion_percentage
                ?? $this->profileCompletion->calculateCompletion($user)),
            'phone_verified' => $user->phone_verified_at !== null,
            'email_verified' => $user->email_verified_at !== null,
        ];
    }

    /**
     * The obligations queue — what the account needs from its owner, ranked.
     *
     * Profile steps arrive already sorted by importance then weight, and the
     * capability-specific asks are merged in by importance rather than simply
     * appended — otherwise a medium ask lands below a low profile step purely
     * because of the order the sources were read. PHP's sort is stable, so
     * equal-importance profile steps keep their weight ordering.
     *
     * @param  array<string, mixed>|null  $contributions
     * @return list<array<string, mixed>>
     */
    private function nextActions(User $user, ?array $contributions): array
    {
        $actions = [];

        foreach ($this->profileCompletion->getPendingSteps($user) as $step) {
            $actions[] = [
                'key' => 'profile.'.$step['step'],
                'label' => $step['label'],
                'why' => $step['description'],
                'importance' => $step['importance'],
                'route' => $step['route'] ?? null,
            ];
        }

        /**
         * A contributor with credits and no verified phone cannot cash out, so
         * that pairing is worth stating outright rather than leaving them to
         * infer it from a generic profile nudge.
         */
        if ($contributions !== null && $contributions['gold_attempts'] < $contributions['gold_attempts_required']) {
            $remaining = $contributions['gold_attempts_required'] - $contributions['gold_attempts'];

            $actions[] = [
                'key' => 'contributions.gold_attempts',
                'label' => 'Take '.$remaining.' more quality checks',
                'why' => 'Earning a contributor tier needs '
                    .$contributions['gold_attempts_required'].' checks — a tier unlocks review work.',
                'importance' => 'medium',
                'route' => null,
            ];
        }

        usort($actions, fn (array $a, array $b) => (self::IMPORTANCE_ORDER[$a['importance']] ?? 3)
            <=> (self::IMPORTANCE_ORDER[$b['importance']] ?? 3));

        return $actions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contributions(User $user): ?array
    {
        $profile = ContributorProfile::query()->where('user_id', $user->id)->first();

        if (! $profile || ! $profile->consented_at) {
            return null;
        }

        $tiers = config('contributions.tiers', []);

        return [
            'tier' => $profile->tier,
            'submissions_total' => (int) $profile->submissions_total,
            'submissions_accepted' => (int) $profile->submissions_accepted,
            'validations_total' => (int) $profile->validations_total,
            'credits_earned_total' => (int) $profile->credits_earned_total,
            // Tier comes from the gold-task pass rate, not from volume, so the
            // client can show what actually moves it.
            'gold_attempts' => (int) $profile->gold_attempts,
            'gold_pass_rate' => (float) $profile->gold_pass_rate,
            'gold_attempts_required' => (int) ($tiers['min_gold_attempts'] ?? 10),
            'trusted_min_pass_rate' => (float) ($tiers['trusted_min_pass_rate'] ?? 85),
        ];
    }

    /**
     * Folded in rather than left to a second request, so an artist's own
     * catalogue reaches the account screen in the same payload as everything
     * else. Uses the cached counters the artist studio already maintains.
     *
     * @return array<string, mixed>|null
     */
    private function artist(User $user): ?array
    {
        $artist = Artist::query()->where('user_id', $user->id)->first();

        if (! $artist) {
            return null;
        }

        $songs = Song::query()->where('artist_id', $artist->id);

        return [
            'stage_name' => $artist->stage_name,
            'slug' => $artist->slug,
            'is_verified' => (bool) $artist->is_verified,
            'total_plays' => (int) ($artist->total_plays_count ?? 0),
            'followers' => (int) ($artist->followers_count ?? 0),
            'songs_published' => (clone $songs)->where('status', 'published')->count(),
            'songs_pending_review' => (clone $songs)->where('status', 'pending')->count(),
            'songs_draft' => (clone $songs)->where('status', 'draft')->count(),
        ];
    }

    /**
     * A small unified timeline of the user's own actions, from the activity
     * spine that already powers Edula.
     *
     * @return list<array{type: string, label: string, at: ?string}>
     */
    private function recentActivity(User $user): array
    {
        return Activity::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(8)
            ->get(['type', 'created_at'])
            ->map(fn (Activity $a) => [
                'type' => $a->type,
                'label' => Str::headline((string) $a->type),
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function asIso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
