<?php

namespace App\Services;

use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\UserCredit;
use App\Notifications\CreditsEarnedNotification;
use App\Services\Credits\RewardRuleService;
use Carbon\Carbon;

class CreditService
{
    // Daily earning limits to prevent abuse
    private const DAILY_LIMITS = [
        'listening' => 50.0,
        'social_interaction' => 30.0,
        'daily_login' => 10.0,
        'content_creation' => 25.0,
        'referral' => 100.0,
    ];

    // Base rates for different activities (in credits)
    private const BASE_RATES = [
        'song_play_complete' => 0.5,
        'song_like' => 1.0,
        'song_share' => 2.0,
        'playlist_create' => 5.0,
        'user_follow' => 1.0,
        'comment_create' => 1.5,
        'daily_login' => 10.0,
        'referral_signup' => 50.0,
        'artist_tip' => 0.0, // Variable amount
        'weekly_streak' => 25.0,
    ];

    public function __construct()
    {
        // Defer rate initialization to avoid issues during seeding/installation
        // $this->ensureDefaultRates();
    }

    /**
     * Award credits for music listening activity
     */
    public function awardListeningCredits(User $user, $songId, int $listenDurationSeconds): ?CreditTransaction
    {
        // Only award for songs listened to completion (>80%)
        if ($listenDurationSeconds < 120) { // Minimum 2 minutes
            return null;
        }

        $today = today();
        $source = 'listening';

        // Check daily limit
        $todayEarned = $this->getTodayEarnings($user, $source);
        if ($todayEarned >= self::DAILY_LIMITS[$source]) {
            return null;
        }

        $credits = $this->getRate('song_play_complete');

        // Bonus for longer listening sessions
        if ($listenDurationSeconds > 300) { // 5+ minutes
            $credits *= 1.5;
        }

        return $this->awardCredits($user, $credits, $source, 'Listened to music', [
            'song_id' => $songId,
            'duration' => $listenDurationSeconds,
        ]);
    }

    /**
     * Award credits for social interactions
     */
    public function awardSocialCredits(User $user, string $action, $targetId = null): ?CreditTransaction
    {
        $source = 'social_interaction';

        // Check daily limit
        $todayEarned = $this->getTodayEarnings($user, $source);
        if ($todayEarned >= self::DAILY_LIMITS[$source]) {
            return null;
        }

        $credits = $this->getRate($action);
        $description = $this->getSocialActionDescription($action);

        return $this->awardCredits($user, $credits, $source, $description, [
            'action' => $action,
            'target_id' => $targetId,
        ]);
    }

    /**
     * Award daily login bonus
     */
    public function awardDailyLoginBonus(User $user): ?CreditTransaction
    {
        $source = 'daily_login';

        /*
         * "Already claimed today" is the rate's own 1440-minute cooldown, and
         * the rules engine reads it off the ledger — so the check, the rate and
         * the daily ceiling all come from one place an operator can edit,
         * instead of a hand-rolled query against a table that never existed.
         */
        $streakDays = $this->getLoginStreak($user);

        $outcome = app(RewardRuleService::class)->award($user, $source, [
            'description' => 'Daily login bonus',
            'metadata' => ['streak_days' => $streakDays],
        ]);

        if (! $outcome->awarded) {
            return null;
        }

        // The streak bonus stays a separate award so it reads as its own line
        // on the ledger, and so a missing weekly_streak rate cannot swallow the
        // daily bonus that already landed.
        if ($streakDays >= 7) {
            app(RewardRuleService::class)->award($user, 'weekly_streak', [
                'description' => 'Seven day streak',
                'metadata' => ['streak_days' => $streakDays],
            ]);
        }

        return $outcome->transaction;
    }

    /**
     * Award referral credits
     */
    public function awardReferralCredits(User $referrer, User $newUser): ?CreditTransaction
    {
        $source = 'referral';
        $credits = $this->getRate('referral_signup');

        // Award to referrer
        $transaction = $this->awardCredits($referrer, $credits, $source, 'Friend referral bonus', [
            'referred_user_id' => $newUser->id,
            'referred_user_name' => $newUser->name,
        ]);

        // Award welcome bonus to new user
        $this->awardCredits($newUser, $credits * 0.5, 'welcome_bonus', 'Welcome to the platform!', [
            'referrer_id' => $referrer->id,
        ]);

        return $transaction;
    }

    /**
     * Process credit spending for promotions
     */
    public function spendCreditsForPromotion(User $user, float $amount, string $promotionType, array $metadata = []): ?CreditTransaction
    {
        $wallet = $this->getUserWallet($user);

        if (! $wallet->hasMinimumBalance($amount)) {
            return null;
        }

        return $wallet->spendCredits(
            $amount,
            'promotion_'.$promotionType,
            'Community promotion: '.ucfirst(str_replace('_', ' ', $promotionType)),
            $metadata
        );
    }

    /**
     * Transfer credits between users
     */
    public function transferCredits(User $from, User $to, float $amount, string $description = ''): ?array
    {
        $fromWallet = $this->getUserWallet($from);

        return $fromWallet->transferCredits($to, $amount, $description ?: 'Credit transfer');
    }

    /**
     * The account's credit wallet, created if it is missing.
     *
     * This read `$user->creditWallet ?: ...->create(...)`. The relation caches
     * its result, so a null from the first call survived the row being created
     * — and the dashboard calls this more than once per request. The second
     * call saw the stale null and inserted again, hitting the unique index on
     * user_id and 500ing the whole endpoint for anybody without a wallet.
     *
     * ensureCreditWallet is a firstOrCreate against the database, so it cannot
     * be fooled by a stale relation, and it is the one way the rest of the
     * codebase already gets a wallet.
     */
    public function getUserWallet(User $user): UserCredit
    {
        return $user->ensureCreditWallet();
    }

    /**
     * Get user's credit balance
     */
    public function getBalance(User $user): float
    {
        $wallet = $this->getUserWallet($user);

        return $wallet->available_credits ?? 0;
    }

    /**
     * Get user's credit stats for index page
     */
    public function getUserCreditStats(User $user): array
    {
        $wallet = $this->getUserWallet($user);

        /*
         * These three sums once asked for 'earn' and 'spend', spellings nothing
         * has ever written, so every total came back zero for every account.
         * The synonyms are retired; there is one form to ask for.
         */
        $earnedTypes = [CreditTransaction::TYPE_EARNED];
        $spentTypes = [CreditTransaction::TYPE_SPENT];

        $totalEarned = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', $earnedTypes)
            ->sum('amount');

        $totalSpent = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', $spentTypes)
            ->sum('amount');

        $thisMonth = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', $earnedTypes)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        $recentTransactions = CreditTransaction::where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        return [
            'totalEarned' => $totalEarned ?? 0,
            'totalSpent' => $totalSpent ?? 0,
            'thisMonth' => $thisMonth ?? 0,
            'recentTransactions' => $recentTransactions,
        ];
    }

    /**
     * Get user's credit summary for dashboard
     */
    public function getUserCreditSummary(User $user): array
    {
        $wallet = $this->getUserWallet($user);
        $totalEarned = $wallet->earned_credits;

        return [
            'available_credits' => $wallet->available_credits,
            'total_earned' => $totalEarned,
            'total_spent' => $wallet->spent_credits,
            'earned_today' => $wallet->credits_earned_today,
            'spent_today' => $wallet->credits_spent_today,
            'earning_potential_remaining' => $this->getRemainingEarningPotential($user),
            'recent_transactions' => $this->getRecentTransactions($user, 5),
            'login_streak' => $this->getLoginStreak($user),
            'next_milestone' => $this->getNextMilestone($totalEarned),
        ];
    }

    // Private helper methods
    private function awardCredits(User $user, float $amount, string $source, string $description, array $metadata = []): CreditTransaction
    {
        $wallet = $this->getUserWallet($user);
        $transaction = $wallet->addCredits($amount, $source, $description, $metadata);

        // Notify user about credits earned
        $user->notify(new CreditsEarnedNotification(
            $amount,
            $source,
            $description,
            $wallet->balance ?? 0
        ));

        return $transaction;
    }

    private function getTodayEarnings(User $user, string $source): float
    {
        return CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [CreditTransaction::TYPE_EARNED, CreditTransaction::TYPE_BONUS])
            ->where('source', $source)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    /**
     * What an activity pays.
     *
     * This queried `action` and `credits_earned`, neither of which is a column
     * on credit_rates — so it raised "Unknown column" on every call and took
     * the whole award down with it. That is why no daily-login, social or
     * referral credit has ever been paid on this platform. The constants remain
     * as the fallback for an activity with no row yet.
     */
    private function getRate(string $activity): float
    {
        $rate = CreditRate::query()->live()->forActivity($activity)->value('credits_per_action');

        return $rate !== null
            ? (float) $rate
            : (self::BASE_RATES[$activity] ?? 1.0);
    }

    /**
     * Consecutive days this account has claimed its login bonus.
     *
     * Read off the credits ledger rather than a parallel activity table. The
     * table this used to query, user_activity_credits, was never created — no
     * model, no migration — so every call threw and took the whole credits
     * dashboard down with it, which is why the page showed a zero balance to
     * people who had credits.
     *
     * Deriving it also removes the second source of truth. The old code wrote
     * its activity row *before* awarding the credits, so a failed award left a
     * record claiming a bonus that never arrived — the same shape of gap that
     * hid the missing tips.
     */
    private function getLoginStreak(User $user): int
    {
        $days = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('source', 'daily_login')
            ->where('created_at', '>=', today()->subDays(30))
            ->pluck('created_at')
            ->map(fn ($at) => Carbon::parse($at)->toDateString())
            ->unique()
            ->flip();

        // A streak is only broken by missing a whole day, so it may end on
        // today or on yesterday — otherwise everybody reads as zero until the
        // moment they claim.
        $cursor = $days->has(today()->toDateString())
            ? today()
            : today()->subDay();

        if (! $days->has($cursor->toDateString())) {
            return 0;
        }

        $streak = 0;

        while ($days->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    private function getRemainingEarningPotential(User $user): array
    {
        $potential = [];

        foreach (self::DAILY_LIMITS as $source => $limit) {
            $earned = $this->getTodayEarnings($user, $source);
            $potential[$source] = max(0, $limit - $earned);
        }

        return $potential;
    }

    private function getRecentTransactions(User $user, int $limit = 10): array
    {
        return CreditTransaction::where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($transaction) {
                return [
                    // Without an id the client has no stable key for these rows,
                    // and /credits/transactions already returns one — the two
                    // shapes should not disagree about something so basic.
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->formatted_amount,
                    'description' => $transaction->description,
                    'source' => $transaction->source_description,
                    'date' => optional($transaction->created_at)->diffForHumans(),
                    'relative_date' => optional($transaction->created_at)->diffForHumans(),
                    'icon' => $transaction->type_icon,
                ];
            })
            ->toArray();
    }

    private function getNextMilestone(float $totalCredits): array
    {
        $milestones = [100, 500, 1000, 2500, 5000, 10000];

        foreach ($milestones as $milestone) {
            if ($totalCredits < $milestone) {
                return [
                    'target' => $milestone,
                    'remaining' => $milestone - $totalCredits,
                    'progress_percentage' => ($totalCredits / $milestone) * 100,
                    'reward' => $this->getMilestoneReward($milestone),
                ];
            }
        }

        return [
            'target' => 'Max level reached',
            'remaining' => 0,
            'progress_percentage' => 100,
            'reward' => 'VIP status unlocked!',
        ];
    }

    private function getMilestoneReward(int $milestone): string
    {
        return match ($milestone) {
            100 => 'Profile badge + 10 bonus credits',
            500 => 'Custom theme + 25 bonus credits',
            1000 => 'Priority support + 50 bonus credits',
            2500 => 'Artist verification + 100 bonus credits',
            5000 => 'VIP features + 200 bonus credits',
            10000 => 'Platform ambassador + 500 bonus credits',
            default => 'Special recognition'
        };
    }

    private function getSocialActionDescription(string $action): string
    {
        return match ($action) {
            'song_like' => 'Liked a song',
            'song_share' => 'Shared a song',
            'playlist_create' => 'Created a playlist',
            'user_follow' => 'Followed a user',
            'comment_create' => 'Added a comment',
            default => 'Social interaction'
        };
    }

    private function ensureDefaultRates(): void
    {
        // Map activities to daily limit categories
        $activityLimits = [
            'song_play_complete' => 'listening',
            'song_like' => 'social_interaction',
            'song_share' => 'social_interaction',
            'playlist_create' => 'content_creation',
            'user_follow' => 'social_interaction',
            'comment_create' => 'social_interaction',
            'daily_login' => 'daily_login',
            'referral_signup' => 'referral',
            'artist_tip' => null,
            'weekly_streak' => null,
        ];

        foreach (self::BASE_RATES as $activity => $rate) {
            $limitCategory = $activityLimits[$activity] ?? null;
            $dailyLimit = $limitCategory ? (self::DAILY_LIMITS[$limitCategory] ?? null) : null;

            CreditRate::firstOrCreate(
                ['action' => $activity],
                [
                    'credits_earned' => (int) $rate,
                    'daily_limit' => $dailyLimit ? (int) $dailyLimit : null,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Get earning opportunities for the earn page
     */
    public function getEarningOpportunities(): array
    {
        return [
            'daily' => [
                [
                    'id' => 'daily_login',
                    'title' => 'Daily Login',
                    'description' => 'Log in every day to earn bonus credits',
                    'credits' => 10,
                    'icon' => 'login',
                    'color' => 'green',
                ],
                [
                    'id' => 'listen_music',
                    'title' => 'Listen to Music',
                    'description' => 'Earn 1 credit per song played (min 2 min)',
                    'credits' => 1,
                    'icon' => 'play_arrow',
                    'color' => 'blue',
                ],
            ],
            'engagement' => [
                [
                    'id' => 'share_song',
                    'title' => 'Share Songs',
                    'description' => 'Share songs on social media',
                    'credits' => 5,
                    'icon' => 'share',
                    'color' => 'purple',
                ],
                [
                    'id' => 'like_song',
                    'title' => 'Like Songs',
                    'description' => 'Like your favorite songs',
                    'credits' => 2,
                    'icon' => 'favorite',
                    'color' => 'pink',
                ],
                [
                    'id' => 'follow_artist',
                    'title' => 'Follow Artists',
                    'description' => 'Follow artists you love',
                    'credits' => 3,
                    'icon' => 'person_add',
                    'color' => 'indigo',
                ],
                [
                    'id' => 'create_playlist',
                    'title' => 'Create Playlists',
                    'description' => 'Create and share playlists',
                    'credits' => 10,
                    'icon' => 'playlist_add',
                    'color' => 'teal',
                ],
            ],
            'referral' => [
                [
                    'id' => 'invite_friend',
                    'title' => 'Invite Friends',
                    'description' => 'Earn 50 credits per friend who signs up',
                    'credits' => 50,
                    'icon' => 'group_add',
                    'color' => 'orange',
                ],
            ],
        ];
    }

    /**
     * Get spending options for the spend page
     */
    public function getSpendingOptions(): array
    {
        return [
            'subscriptions' => [
                [
                    'id' => 'premium_1m',
                    'title' => '1 Month Premium',
                    'description' => 'Unlock all premium features',
                    'cost' => 15000,
                    'icon' => 'workspace_premium',
                    'color' => 'purple',
                    'features' => ['Unlimited downloads', 'HD audio', 'No ads', 'Offline mode'],
                ],
                [
                    'id' => 'premium_3m',
                    'title' => '3 Months Premium',
                    'description' => 'Save 20% on premium',
                    'cost' => 36000,
                    'icon' => 'workspace_premium',
                    'color' => 'indigo',
                    'features' => ['All 1 month features', '20% discount'],
                ],
            ],
            'rewards' => [
                [
                    'id' => 'profile_boost',
                    'title' => 'Profile Boost',
                    'description' => 'Boost your profile for 24 hours',
                    'cost' => 500,
                    'icon' => 'trending_up',
                    'color' => 'blue',
                ],
                [
                    'id' => 'exclusive_content',
                    'title' => 'Exclusive Content',
                    'description' => 'Unlock exclusive artist content',
                    'cost' => 1000,
                    'icon' => 'star',
                    'color' => 'yellow',
                ],
            ],
        ];
    }

    /**
     * Get transaction history for history page
     */
    public function getTransactionHistory(User $user, ?string $type = null, ?string $category = null, ?string $date = null): array
    {
        $query = CreditTransaction::where('user_id', $user->id);

        if ($type) {
            $query->where('type', $type);
        }

        if ($category) {
            $query->where('source', $category);
        }

        if ($date) {
            $query->whereDate('created_at', $date);
        }

        $transactions = $query->latest()->paginate(20);

        $totalEarned = CreditTransaction::where('user_id', $user->id)
            ->where('type', 'earn')
            ->sum('amount');

        $totalSpent = CreditTransaction::where('user_id', $user->id)
            ->where('type', 'spend')
            ->sum('amount');

        $thisMonth = CreditTransaction::where('user_id', $user->id)
            ->where('type', 'earn')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        return [
            'transactions' => $transactions,
            'totalEarned' => $totalEarned ?? 0,
            'totalSpent' => $totalSpent ?? 0,
            'thisMonth' => $thisMonth ?? 0,
        ];
    }

    /**
     * Claim daily bonus
     */
    public function claimDailyBonus(User $user): array
    {
        $result = $this->awardDailyLoginBonus($user);

        if (! $result) {
            throw new \Exception('Daily bonus already claimed today');
        }

        $wallet = $this->getUserWallet($user);

        return [
            'credits' => $result->amount,
            'balance' => $wallet->available_credits ?? 0,
        ];
    }
}
