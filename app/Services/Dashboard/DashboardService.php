<?php

namespace App\Services\Dashboard;

use App\Models\Activity;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Services\Commerce\SettlementService;
use Illuminate\Support\Str;

/**
 * Assembles the unified, capability-aware account dashboard for any user — the
 * non-artist counterpart to the artist studio. Pulls from existing sources
 * (wallet, the settlement ledger, play history, contributor profile, capability
 * grants); sections are present only when relevant to the user.
 *
 * @phpstan-type Money array{ugx: float, credits: int}
 */
class DashboardService
{
    public function __construct(private readonly SettlementService $settlements) {}

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

        return [
            'wallet' => [
                'ugx_balance' => (float) ($user->ugx_balance ?? 0),
                'credits_balance' => (int) $user->credit_balance,
            ],
            'earnings' => [
                // Money owed to the user across every vertical (music, store,
                // events, promotions, contributions) from the unified ledger.
                'pending' => $balances['pending'],
                'available' => $balances['cleared'],
                'paid_out' => $balances['paid_out'],
            ],
            'listening' => [
                'plays_total' => $user->playHistory()->count(),
                'plays_30d' => $user->playHistory()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'capabilities' => $capabilities,
            'contributions' => $this->contributions($user),
            'recent_activity' => $this->recentActivity($user),
        ];
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

        return [
            'tier' => $profile->tier,
            'submissions_total' => (int) $profile->submissions_total,
            'submissions_accepted' => (int) $profile->submissions_accepted,
            'validations_total' => (int) $profile->validations_total,
            'credits_earned_total' => (int) $profile->credits_earned_total,
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
}
