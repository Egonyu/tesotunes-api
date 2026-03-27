<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Arr;

class EventCommissionSimulationService
{
    public function __construct(
        private readonly EventFeeCalculatorService $eventFeeCalculatorService,
    ) {}

    public function simulate(
        ?User $organizer,
        array $ticketTiers,
        string $ticketingMode = Event::TICKETING_MODE_TESOTUNES_MANAGED,
        string $currency = 'UGX',
    ): array {
        $currency = strtoupper(trim($currency)) ?: 'UGX';
        $ticketingMode = in_array($ticketingMode, [
            Event::TICKETING_MODE_TESOTUNES_MANAGED,
            Event::TICKETING_MODE_HYBRID,
            Event::TICKETING_MODE_EXTERNAL_ONLY,
            Event::TICKETING_MODE_FREE_RSVP,
        ], true) ? $ticketingMode : Event::TICKETING_MODE_TESOTUNES_MANAGED;

        $simulationEvent = new Event([
            'ticketing_mode' => $ticketingMode,
        ]);

        if ($organizer) {
            $simulationEvent->setRelation('organizer', $organizer);
            $simulationEvent->setRelation('user', $organizer);
        }

        $items = [];
        $totals = [
            'ticket_count' => 0,
            'gross_revenue' => 0.0,
            'customer_paid_total' => 0.0,
            'tesotunes_fee_revenue' => 0.0,
            'platform_commission_amount' => 0.0,
            'processing_fee_amount' => 0.0,
            'organizer_net_amount' => 0.0,
        ];
        $organizerPlan = null;
        $feeSource = 'event_settings';
        $platformCommissionPercent = 0.0;
        $processingFeePercent = 0.0;

        foreach ($ticketTiers as $index => $tier) {
            $quantity = max(0, (int) ($tier['quantity'] ?? 0));
            $priceUgx = round(max(0, (float) ($tier['price'] ?? $tier['price_ugx'] ?? 0)), 2);
            $priceCredits = round(max(0, (float) ($tier['price_credits'] ?? 0)), 2);
            $name = trim((string) ($tier['name'] ?? 'Tier '.($index + 1)));

            if ($quantity < 1) {
                continue;
            }

            $quote = $ticketingMode === Event::TICKETING_MODE_EXTERNAL_ONLY
                ? $this->buildExternalOnlyQuote($quantity, $priceUgx, $priceCredits)
                : $this->eventFeeCalculatorService->calculateForEvent(
                    event: $simulationEvent,
                    unitPriceUgx: $ticketingMode === Event::TICKETING_MODE_FREE_RSVP ? 0.0 : $priceUgx,
                    unitPriceCredits: $priceCredits,
                    quantity: $quantity,
                );

            $items[] = [
                'name' => $name !== '' ? $name : 'Tier '.($index + 1),
                'quantity' => $quantity,
                'unit_price_ugx' => (float) $quote['unit_price_ugx'],
                'unit_price_credits' => (float) $quote['unit_price_credits'],
                'gross_revenue' => (float) $quote['base_amount'],
                'customer_paid_total' => (float) $quote['total_amount'],
                'tesotunes_fee_revenue' => (float) $quote['total_fee_amount'],
                'platform_commission_percent' => (float) $quote['platform_commission_percent'],
                'platform_commission_amount' => (float) $quote['platform_commission_amount'],
                'processing_fee_percent' => (float) $quote['processing_fee_percent'],
                'processing_fee_amount' => (float) $quote['processing_fee_amount'],
                'organizer_net_amount' => (float) $quote['organizer_net_amount'],
                'fee_source' => $quote['fee_source'],
            ];

            $totals['ticket_count'] += $quantity;
            $totals['gross_revenue'] += (float) $quote['base_amount'];
            $totals['customer_paid_total'] += (float) $quote['total_amount'];
            $totals['tesotunes_fee_revenue'] += (float) $quote['total_fee_amount'];
            $totals['platform_commission_amount'] += (float) $quote['platform_commission_amount'];
            $totals['processing_fee_amount'] += (float) $quote['processing_fee_amount'];
            $totals['organizer_net_amount'] += (float) $quote['organizer_net_amount'];

            $organizerPlan ??= $quote['organizer_plan'];
            $feeSource = (string) ($quote['fee_source'] ?? $feeSource);
            $platformCommissionPercent = (float) ($quote['platform_commission_percent'] ?? $platformCommissionPercent);
            $processingFeePercent = (float) ($quote['processing_fee_percent'] ?? $processingFeePercent);
        }

        $normalizedTotals = array_map(
            static fn ($value) => is_float($value) ? round($value, 2) : $value,
            $totals,
        );

        return [
            'ticketing_mode' => $ticketingMode,
            'mode_label' => match ($ticketingMode) {
                Event::TICKETING_MODE_HYBRID => 'Hybrid',
                Event::TICKETING_MODE_EXTERNAL_ONLY => 'External only',
                Event::TICKETING_MODE_FREE_RSVP => 'Free RSVP',
                default => 'Tesotunes managed',
            },
            'tesotunes_checkout_enabled' => in_array($ticketingMode, [
                Event::TICKETING_MODE_TESOTUNES_MANAGED,
                Event::TICKETING_MODE_HYBRID,
                Event::TICKETING_MODE_FREE_RSVP,
            ], true),
            'currency' => $currency,
            'fee_source' => $ticketingMode === Event::TICKETING_MODE_EXTERNAL_ONLY ? 'external_only_mode' : $feeSource,
            'organizer_plan' => $organizerPlan,
            'platform_commission_percent' => round($platformCommissionPercent, 2),
            'processing_fee_percent' => round($processingFeePercent, 2),
            'totals' => $normalizedTotals,
            'items' => $items,
            'scenarios' => [
                $this->buildScenario('sellout', 'Sell-out projection', 1.0, $normalizedTotals),
                $this->buildScenario('strong', 'Strong turnout (75%)', 0.75, $normalizedTotals),
                $this->buildScenario('conservative', 'Conservative turnout (50%)', 0.5, $normalizedTotals),
            ],
            'upgrade_nudges' => $this->buildUpgradeNudges(
                currentPlanId: $organizerPlan['id'] ?? null,
                currentGrossRevenue: (float) ($normalizedTotals['gross_revenue'] ?? 0),
                currentPlatformCommissionPercent: $platformCommissionPercent,
                currentProcessingFeePercent: $processingFeePercent,
            ),
            'notes' => $this->buildNotes($ticketingMode),
        ];
    }

    private function buildExternalOnlyQuote(int $quantity, float $priceUgx, float $priceCredits): array
    {
        $baseAmount = round($priceUgx * $quantity, 2);
        $creditAmount = round($priceCredits * $quantity, 2);

        return [
            'quantity' => $quantity,
            'currency' => 'UGX',
            'unit_price_ugx' => $priceUgx,
            'unit_price_credits' => $priceCredits,
            'base_amount' => $baseAmount,
            'discount_amount' => 0.0,
            'discounted_base_amount' => $baseAmount,
            'total_credits' => $creditAmount,
            'platform_commission_percent' => 0.0,
            'platform_commission_amount' => 0.0,
            'processing_fee_percent' => 0.0,
            'processing_fee_amount' => 0.0,
            'total_fee_amount' => 0.0,
            'total_amount' => $baseAmount,
            'organizer_net_amount' => $baseAmount,
            'fee_source' => 'external_only_mode',
            'organizer_plan' => null,
        ];
    }

    private function buildScenario(string $key, string $label, float $ratio, array $totals): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'sell_through_percent' => (int) round($ratio * 100),
            'ticket_count' => (int) round(((int) ($totals['ticket_count'] ?? 0)) * $ratio),
            'gross_revenue' => round(((float) ($totals['gross_revenue'] ?? 0)) * $ratio, 2),
            'customer_paid_total' => round(((float) ($totals['customer_paid_total'] ?? 0)) * $ratio, 2),
            'tesotunes_fee_revenue' => round(((float) ($totals['tesotunes_fee_revenue'] ?? 0)) * $ratio, 2),
            'organizer_net_amount' => round(((float) ($totals['organizer_net_amount'] ?? 0)) * $ratio, 2),
        ];
    }

    private function buildNotes(string $ticketingMode): array
    {
        return match ($ticketingMode) {
            Event::TICKETING_MODE_EXTERNAL_ONLY => [
                'Tesotunes checkout is disabled for external-only events.',
                'This simulation is informational only and does not apply Tesotunes ticketing fees.',
            ],
            Event::TICKETING_MODE_FREE_RSVP => [
                'Free RSVP mode does not generate ticket revenue or ticketing fees.',
                'Use this to project attendance volume rather than payout.',
            ],
            Event::TICKETING_MODE_HYBRID => [
                'Hybrid mode estimates Tesotunes-managed inventory only.',
                'External and printed allocations should be reconciled separately in event operations.',
            ],
            default => [
                'Figures are estimated from the current organizer fee contract and ticket setup.',
                'Final payout still depends on actual paid orders, refunds, and dispute outcomes.',
            ],
        };
    }

    private function buildUpgradeNudges(
        ?int $currentPlanId,
        float $currentGrossRevenue,
        float $currentPlatformCommissionPercent,
        float $currentProcessingFeePercent,
    ): array {
        if ($currentGrossRevenue <= 0) {
            return [];
        }

        $currentCombinedPercent = round($currentPlatformCommissionPercent + $currentProcessingFeePercent, 2);

        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->when($currentPlanId, fn ($query) => $query->where('id', '!=', $currentPlanId))
            ->get()
            ->map(function (SubscriptionPlan $plan) use ($currentGrossRevenue, $currentCombinedPercent) {
                $platformPercent = $this->resolvePlanRate(
                    $plan,
                    ['event_platform_commission_percent', 'events.platform_commission_percent', 'platform_commission_percent']
                );
                $processingPercent = $this->resolvePlanRate(
                    $plan,
                    ['event_processing_fee_percent', 'events.processing_fee_percent', 'processing_fee_percent']
                );

                if ($platformPercent === null && $processingPercent === null) {
                    return null;
                }

                $planPlatformPercent = round((float) ($platformPercent ?? 0), 2);
                $planProcessingPercent = round((float) ($processingPercent ?? 0), 2);
                $planCombinedPercent = round($planPlatformPercent + $planProcessingPercent, 2);

                if ($planCombinedPercent >= $currentCombinedPercent) {
                    return null;
                }

                $estimatedFeeSavings = round($currentGrossRevenue * (($currentCombinedPercent - $planCombinedPercent) / 100), 2);
                $estimatedOrganizerNet = round($currentGrossRevenue - ($currentGrossRevenue * ($planCombinedPercent / 100)), 2);
                $priceLocal = (float) ($plan->price_local ?? $plan->price_monthly ?? $plan->price ?? 0);
                $breakEvenRevenue = $currentCombinedPercent > $planCombinedPercent
                    ? round($priceLocal / (($currentCombinedPercent - $planCombinedPercent) / 100), 2)
                    : null;

                return [
                    'plan_id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'tier' => $plan->tier,
                    'price_local' => round($priceLocal, 2),
                    'currency' => strtoupper((string) ($plan->currency ?? 'UGX')),
                    'platform_commission_percent' => $planPlatformPercent,
                    'processing_fee_percent' => $planProcessingPercent,
                    'estimated_fee_savings' => $estimatedFeeSavings,
                    'estimated_organizer_net' => $estimatedOrganizerNet,
                    'break_even_revenue' => $breakEvenRevenue,
                ];
            })
            ->filter()
            ->sortByDesc('estimated_fee_savings')
            ->take(3)
            ->values()
            ->all();
    }

    private function resolvePlanRate(SubscriptionPlan $plan, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = Arr::get($plan->metadata ?? [], $key);
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
