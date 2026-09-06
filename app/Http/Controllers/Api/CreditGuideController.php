<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditRate;
use App\Models\User;
use App\Services\Credits\RewardRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The credits guide — every way to earn, read from the rate table the rewards
 * engine actually enforces.
 *
 * Public, because someone deciding whether to join should be able to read what
 * the platform pays. When a token is present the same rows carry that user's
 * remaining allowance, so the guide doubles as their tracker.
 */
class CreditGuideController extends Controller
{
    /**
     * Presentation grouping for the rate catalogue. Rates are stored flat; this
     * is the only place that decides how they read, ordered so the work the
     * platform most needs comes first rather than the smallest reward.
     *
     * @var array<string, array{label: string, blurb: string, activities: list<string>}>
     */
    private const GROUPS = [
        'contributions' => [
            'label' => 'Ateso corpus',
            'blurb' => 'The work the platform most needs, and it pays accordingly.',
            'activities' => ['contribution_translation', 'contribution_validation'],
        ],
        'one_off' => [
            'label' => 'One-off rewards',
            'blurb' => 'Paid once, when you set your account up properly.',
            'activities' => ['profile_complete', 'referral_welcome'],
        ],
        'referrals' => [
            'label' => 'Bringing people in',
            'blurb' => 'Paid for every friend who joins with your code.',
            'activities' => ['referral_signup'],
        ],
        'listening' => [
            'label' => 'Listening',
            'blurb' => 'A play counts at 90% listened, with no skipping forward.',
            'activities' => ['song_play_complete'],
        ],
        'showing_up' => [
            'label' => 'Showing up',
            'blurb' => 'Small, but it adds up if you come back.',
            'activities' => ['daily_login'],
        ],
        'social' => [
            'label' => 'Supporting artists',
            'blurb' => 'Each has its own daily ceiling, so spread them out.',
            'activities' => [
                'social_share',
                'social_comment',
                'social_like',
                'social_follow',
                'playlist_create',
            ],
        ],
    ];

    public function __construct(private readonly RewardRuleService $rewardRules) {}

    /**
     * GET /api/credits/guide
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();
        $rates = CreditRate::query()->live()->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'personalised' => $user !== null,
                'groups' => $this->groups($rates, $user),
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CreditRate>  $rates
     * @return list<array<string, mixed>>
     */
    private function groups($rates, ?User $user): array
    {
        $byActivity = $rates->keyBy('activity_type');
        $placed = [];
        $groups = [];

        foreach (self::GROUPS as $key => $group) {
            $rows = [];

            foreach ($group['activities'] as $activity) {
                $rate = $byActivity->get($activity);

                if (! $rate) {
                    continue;
                }

                $placed[] = $activity;
                $rows[] = $this->row($rate, $user);
            }

            if ($rows !== []) {
                $groups[] = [
                    'key' => $key,
                    'label' => $group['label'],
                    'blurb' => $group['blurb'],
                    'rates' => $rows,
                ];
            }
        }

        /**
         * Anything the grouping does not know about still has to appear — a new
         * rate added in the admin must show up in the guide without a deploy,
         * otherwise the page silently understates what the platform pays.
         */
        $ungrouped = $rates->reject(fn (CreditRate $rate) => in_array($rate->activity_type, $placed, true));

        if ($ungrouped->isNotEmpty()) {
            $groups[] = [
                'key' => 'more',
                'label' => 'More ways to earn',
                'blurb' => 'Newer rewards.',
                'rates' => $ungrouped->map(fn (CreditRate $rate) => $this->row($rate, $user))->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CreditRate $rate, ?User $user): array
    {
        $dailyLimit = $rate->daily_limit !== null ? (float) $rate->daily_limit : null;

        $row = [
            'activity_type' => $rate->activity_type,
            'label' => $rate->label(),
            'description' => $rate->description,
            'credits' => (float) $rate->credits_per_action,
            'daily_limit' => $dailyLimit,
            'cooldown_minutes' => $rate->cooldown_minutes,
        ];

        if (! $user) {
            return $row;
        }

        $earnedToday = $this->rewardRules->earnedToday($user, $rate->activity_type);

        return $row + [
            'earned_today' => $earnedToday,
            'remaining_today' => $dailyLimit !== null
                ? max(0, round($dailyLimit - $earnedToday, 2))
                : null,
            'available_in_minutes' => $rate->cooldown_minutes
                ? $this->rewardRules->cooldownRemaining($user, $rate->activity_type, $rate->cooldown_minutes)
                : 0,
        ];
    }
}
