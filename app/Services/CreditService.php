<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCredit;
use App\Models\CreditTransaction;
use App\Models\UserActivityCredit;
use App\Models\CreditRate;
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
            'duration' => $listenDurationSeconds
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
            'target_id' => $targetId
        ]);
    }

    /**
     * Award daily login bonus
     */
    public function awardDailyLoginBonus(User $user): ?CreditTransaction
    {
        $today = today();
        $source = 'daily_login';

        // Check if already claimed today
        $alreadyClaimed = UserActivityCredit::where('user_id', $user->id)
            ->where('activity_type', $source)
            ->where('activity_date', $today)
            ->exists();

        if ($alreadyClaimed) {
            return null;
        }

        $credits = $this->getRate('daily_login');

        // Streak bonus
        $streakDays = $this->getLoginStreak($user);
        if ($streakDays >= 7) {
            $credits += $this->getRate('weekly_streak');
        }

        // Record the activity
        UserActivityCredit::create([
            'user_id' => $user->id,
            'activity_type' => $source,
            'activity_date' => $today,
            'credits_earned' => $credits,
            'activity_data' => ['streak_days' => $streakDays]
        ]);

        return $this->awardCredits($user, $credits, $source, 'Daily login bonus', [
            'streak_days' => $streakDays
        ]);
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
            'referred_user_name' => $newUser->name
        ]);

        // Award welcome bonus to new user
        $this->awardCredits($newUser, $credits * 0.5, 'welcome_bonus', 'Welcome to the platform!', [
            'referrer_id' => $referrer->id
        ]);

        return $transaction;
    }

    /**
     * Process credit spending for promotions
     */
    public function spendCreditsForPromotion(User $user, float $amount, string $promotionType, array $metadata = []): ?CreditTransaction
    {
        $wallet = $this->getUserWallet($user);

        if (!$wallet->hasMinimumBalance($amount)) {
            return null;
        }

        return $wallet->spendCredits(
            $amount,
            'promotion_' . $promotionType,
            'Community promotion: ' . ucfirst(str_replace('_', ' ', $promotionType)),
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
     * Get user's credit wallet, create if doesn't exist
     */
    public function getUserWallet(User $user): UserCredit
    {
        return $user->creditWallet ?: $user->creditWallet()->create([]);
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

        // Get transaction statistics
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

        return [
            'available_credits' => $wallet->available_credits,
            'total_earned' => $wallet->earned_credits,
            'total_spent' => $wallet->spent_credits,
            'earned_today' => $wallet->credits_earned_today,
            'spent_today' => $wallet->credits_spent_today,
            'earning_potential_remaining' => $this->getRemainingEarningPotential($user),
            'recent_transactions' => $this->getRecentTransactions($user, 5),
            'login_streak' => $this->getLoginStreak($user),
            'next_milestone' => $this->getNextMilestone($wallet->total_lifetime_credits ?? 0),
        ];
    }

    /**
     * Get community promotion opportunities
     */
    public function getPromotionOpportunities(User $user): array
    {
        $wallet = $this->getUserWallet($user);
        $availableCredits = $wallet->available_credits;

        $opportunities = [];

        // Artist shoutout opportunities
        if ($availableCredits >= 25) {
            $opportunities[] = [
                'type' => 'artist_shoutout',
                'title' => 'Get Artist Shoutout',
                'description' => 'Get mentioned by popular artists',
                'cost' => 25,
                'benefit' => 'Social media mention + increased followers',
                'available' => true
            ];
        }

        // Playlist feature opportunities
        if ($availableCredits >= 15) {
            $opportunities[] = [
                'type' => 'playlist_feature',
                'title' => 'Feature in Playlist',
                'description' => 'Get your music featured in popular playlists',
                'cost' => 15,
                'benefit' => 'Increased plays + discovery',
                'available' => true
            ];
        }

        // Profile boost
        if ($availableCredits >= 20) {
            $opportunities[] = [
                'type' => 'profile_boost',
                'title' => 'Profile Boost',
                'description' => 'Boost your profile visibility for 24 hours',
                'cost' => 20,
                'benefit' => 'Increased profile views + followers',
                'available' => true
            ];
        }

        return $opportunities;
    }

    // Private helper methods
    private function awardCredits(User $user, float $amount, string $source, string $description, array $metadata = []): CreditTransaction
    {
        $wallet = $this->getUserWallet($user);
        return $wallet->addCredits($amount, $source, $description, $metadata);
    }

    private function getTodayEarnings(User $user, string $source): float
    {
        return CreditTransaction::where('user_id', $user->id)
            ->where('following_type', 'earned')
            ->where('source', $source)
            ->whereDate('processed_at', today())
            ->sum('amount');
    }

    private function getRate(string $activity): float
    {
        return CreditRate::where('action', $activity)
                         ->where('is_active', true)
                         ->value('credits_earned') ?? (self::BASE_RATES[$activity] ?? 1.0);
    }

    private function getLoginStreak(User $user): int
    {
        $streak = 0;
        $date = today();

        while ($date->gte(today()->subDays(30))) {
            $hasLogin = UserActivityCredit::where('user_id', $user->id)
                ->where('activity_type', 'daily_login')
                ->where('activity_date', $date)
                ->exists();

            if ($hasLogin) {
                $streak++;
                $date->subDay();
            } else {
                break;
            }
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
            ->latest('processed_at')
            ->limit($limit)
            ->get()
            ->map(function ($transaction) {
                return [
                    'type' => $transaction->type,
                    'amount' => $transaction->formatted_amount,
                    'description' => $transaction->description,
                    'source' => $transaction->source_description,
                    'date' => $transaction->processed_at->diffForHumans(),
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
        return match($milestone) {
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
        return match($action) {
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
                    'credits_earned' => (int)$rate,
                    'daily_limit' => $dailyLimit ? (int)$dailyLimit : null,
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

        if (!$result) {
            throw new \Exception('Daily bonus already claimed today');
        }

        $wallet = $this->getUserWallet($user);

        return [
            'credits' => $result->amount,
            'balance' => $wallet->available_credits ?? 0,
        ];
    }
}