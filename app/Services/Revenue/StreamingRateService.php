<?php

namespace App\Services\Revenue;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Arr;

class StreamingRateService
{
    public const DEFAULT_FREE_STREAM_RATE_UGX = 5.0;

    public const DEFAULT_PREMIUM_STREAM_RATE_UGX = 15.0;

    public const DEFAULT_STREAMING_COMMISSION_PERCENT = 15.0;

    public function resolveRateForUser(?User $user, bool $fallbackPremium = false): float
    {
        $plan = $user?->getActivePlan();

        return $this->resolveRateForPlan($plan, $fallbackPremium);
    }

    public function resolveRateForUserId(?int $userId, bool $fallbackPremium = false): float
    {
        if (! $userId) {
            return $fallbackPremium
                ? $this->defaultPremiumRate()
                : $this->defaultFreeRate();
        }

        $subscription = $this->resolveActiveSubscription($userId);

        return $this->resolveRateForPlan($subscription?->subscriptionPlan, $fallbackPremium);
    }

    public function resolveRateForPlan(?SubscriptionPlan $plan, bool $fallbackPremium = false): float
    {
        $configuredRate = (float) Arr::get($plan?->metadata ?? [], 'stream_rate_ugx', 0);

        if ($configuredRate > 0) {
            return round($configuredRate, 2);
        }

        if ($plan) {
            $premiumTiers = ['premium', 'artist', 'label', 'vip'];
            if (in_array(strtolower((string) ($plan->tier ?? $plan->slug ?? '')), $premiumTiers, true)) {
                return $this->defaultPremiumRate();
            }
        }

        return $fallbackPremium
            ? $this->defaultPremiumRate()
            : $this->defaultFreeRate();
    }

    public function resolveStreamingCommissionPercent(): float
    {
        $commissions = Setting::get('platform_commissions', []);
        $configured = (float) ($commissions['streaming_percent'] ?? self::DEFAULT_STREAMING_COMMISSION_PERCENT);

        return round($configured, 2);
    }

    /**
     * Admin-configurable default free-tier stream rate. Falls back to the code
     * constant only when no setting row exists.
     */
    public function defaultFreeRate(): float
    {
        $configured = (float) Setting::get('streaming_default_free_rate_ugx', self::DEFAULT_FREE_STREAM_RATE_UGX);

        return $configured > 0 ? round($configured, 2) : self::DEFAULT_FREE_STREAM_RATE_UGX;
    }

    /**
     * Admin-configurable default premium-tier stream rate. Falls back to the
     * code constant only when no setting row exists.
     */
    public function defaultPremiumRate(): float
    {
        $configured = (float) Setting::get('streaming_default_premium_rate_ugx', self::DEFAULT_PREMIUM_STREAM_RATE_UGX);

        return $configured > 0 ? round($configured, 2) : self::DEFAULT_PREMIUM_STREAM_RATE_UGX;
    }

    public function calculateStreamPayout(?int $userId = null, bool $fallbackPremium = false): array
    {
        $ratePerStream = $this->resolveRateForUserId($userId, $fallbackPremium);

        return $this->calculatePayoutFromRate($ratePerStream);
    }

    public function describePlan(?SubscriptionPlan $plan, bool $fallbackPremium = false): array
    {
        $configuredRate = Arr::get($plan?->metadata ?? [], 'stream_rate_ugx');
        $payout = $this->calculatePayoutFromRate($this->resolveRateForPlan($plan, $fallbackPremium));

        return [
            'configured_stream_rate_ugx' => $configuredRate,
            'effective_stream_rate_ugx' => number_format($payout['rate_per_stream'], 2, '.', ''),
            'streaming_commission_percent' => number_format($payout['commission_percent'], 2, '.', ''),
            'estimated_platform_fee_ugx' => number_format($payout['platform_fee'], 2, '.', ''),
            'estimated_net_per_stream_ugx' => number_format($payout['net_amount'], 2, '.', ''),
            'rate_source' => $configuredRate !== null && (float) $configuredRate > 0
                ? 'plan_metadata'
                : $this->inferDefaultRateSource($plan, $fallbackPremium),
        ];
    }

    public function describeListenerContext(?int $userId = null, bool $fallbackPremium = false): array
    {
        $subscription = $this->resolveActiveSubscription($userId);
        $plan = $subscription?->subscriptionPlan;
        $configuredRate = Arr::get($plan?->metadata ?? [], 'stream_rate_ugx');
        $payout = $this->calculatePayoutFromRate($this->resolveRateForPlan($plan, $fallbackPremium));

        return [
            'listener_user_id' => $userId,
            'has_active_subscription' => (bool) $subscription,
            'listener_plan_id' => $plan?->id,
            'listener_plan_slug' => $plan?->slug,
            'listener_plan_name' => $plan?->name,
            'listener_plan_tier' => $plan?->tier,
            'configured_stream_rate_ugx' => $configuredRate !== null
                ? number_format((float) $configuredRate, 2, '.', '')
                : null,
            'effective_stream_rate_ugx' => number_format($payout['rate_per_stream'], 2, '.', ''),
            'streaming_commission_percent' => number_format($payout['commission_percent'], 2, '.', ''),
            'platform_fee_ugx' => number_format($payout['platform_fee'], 2, '.', ''),
            'net_amount_ugx' => number_format($payout['net_amount'], 2, '.', ''),
            'rate_source' => $configuredRate !== null && (float) $configuredRate > 0
                ? 'plan_metadata'
                : $this->inferDefaultRateSource($plan, $fallbackPremium),
        ];
    }

    public function buildStreamAuditPayload(?int $userId = null, bool $fallbackPremium = false, array $context = []): array
    {
        return array_filter(
            array_merge([
                'audit_type' => 'stream_payout',
            ], $this->describeListenerContext($userId, $fallbackPremium), $context),
            static fn ($value) => $value !== null && $value !== ''
        );
    }

    public function encodeAuditPayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
            ?: '{}';
    }

    public function getStreamingConfigurationSummary(): array
    {
        $freePayout = $this->calculatePayoutFromRate($this->defaultFreeRate());
        $premiumPayout = $this->calculatePayoutFromRate($this->defaultPremiumRate());
        $commissionPercent = number_format($freePayout['commission_percent'], 2, '.', '');

        return [
            'calculation_basis' => 'recorded_artist_revenues',
            'streaming_commission_percent' => $commissionPercent,
            'default_free_stream_rate_ugx' => number_format($freePayout['rate_per_stream'], 2, '.', ''),
            'default_free_net_per_stream_ugx' => number_format($freePayout['net_amount'], 2, '.', ''),
            'default_premium_stream_rate_ugx' => number_format($premiumPayout['rate_per_stream'], 2, '.', ''),
            'default_premium_net_per_stream_ugx' => number_format($premiumPayout['net_amount'], 2, '.', ''),
        ];
    }

    private function calculatePayoutFromRate(float $ratePerStream): array
    {
        $commissionPercent = $this->resolveStreamingCommissionPercent();
        $platformFee = round($ratePerStream * ($commissionPercent / 100), 2);
        $netAmount = round($ratePerStream - $platformFee, 2);

        return [
            'rate_per_stream' => round($ratePerStream, 2),
            'commission_percent' => round($commissionPercent, 2),
            'platform_fee' => $platformFee,
            'net_amount' => $netAmount,
        ];
    }

    private function inferDefaultRateSource(?SubscriptionPlan $plan, bool $fallbackPremium): string
    {
        if ($plan) {
            $premiumTiers = ['premium', 'artist', 'label', 'vip'];

            return in_array(strtolower((string) ($plan->tier ?? $plan->slug ?? '')), $premiumTiers, true)
                ? 'default_premium'
                : 'default_free';
        }

        return $fallbackPremium ? 'default_premium' : 'default_free';
    }

    private function resolveActiveSubscription(?int $userId): ?UserSubscription
    {
        if (! $userId) {
            return null;
        }

        return UserSubscription::with('subscriptionPlan')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }
}
