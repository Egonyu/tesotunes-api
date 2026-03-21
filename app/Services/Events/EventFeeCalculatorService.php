<?php

namespace App\Services\Events;

use App\Models\Artist;
use App\Models\Event;
use App\Models\EventDiscountCode;
use App\Models\EventTicket;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Settings\EventSettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EventFeeCalculatorService
{
    public function __construct(
        private readonly EventSettingsService $eventSettingsService,
    ) {}

    public function calculateForTicket(EventTicket $ticket, int $quantity, ?EventDiscountCode $discountCode = null): array
    {
        $event = $ticket->relationLoaded('event')
            ? $ticket->event
            : $ticket->event()->with('organizer.artist', 'user.artist')->first();

        return $this->calculateForEvent(
            event: $event,
            unitPriceUgx: (float) ($ticket->price_ugx ?? 0),
            unitPriceCredits: (float) ($ticket->price_credits ?? 0),
            quantity: $quantity,
            discountCode: $discountCode,
            discountEligible: $discountCode ? $discountCode->appliesToTicketId((int) $ticket->id) : false,
        );
    }

    public function calculateForEvent(
        ?Event $event,
        float $unitPriceUgx,
        float $unitPriceCredits,
        int $quantity,
        ?EventDiscountCode $discountCode = null,
        bool $discountEligible = false,
    ): array {
        $quantity = max(1, $quantity);
        $baseAmount = round($unitPriceUgx * $quantity, 2);
        $creditAmount = round($unitPriceCredits * $quantity, 2);
        $discountAmount = ($discountCode && $discountEligible)
            ? $discountCode->calculateDiscountForAmount($baseAmount)
            : 0.0;
        $discountedBaseAmount = round(max(0, $baseAmount - $discountAmount), 2);

        $feeConfig = $this->resolveFeeConfiguration($event?->organizer ?? $event?->user);
        $platformCommissionAmount = round($discountedBaseAmount * ($feeConfig['platform_commission_percent'] / 100), 2);
        $processingFeeAmount = round($discountedBaseAmount * ($feeConfig['processing_fee_percent'] / 100), 2);
        $totalFeeAmount = round($platformCommissionAmount + $processingFeeAmount, 2);
        $totalAmount = round($discountedBaseAmount + $totalFeeAmount, 2);
        $organizerNetAmount = round(max(0, $discountedBaseAmount - $platformCommissionAmount - $processingFeeAmount), 2);

        return [
            'quantity' => $quantity,
            'currency' => 'UGX',
            'unit_price_ugx' => round($unitPriceUgx, 2),
            'unit_price_credits' => round($unitPriceCredits, 2),
            'base_amount' => $baseAmount,
            'discount_amount' => round($discountAmount, 2),
            'discounted_base_amount' => $discountedBaseAmount,
            'total_credits' => $creditAmount,
            'platform_commission_percent' => $feeConfig['platform_commission_percent'],
            'platform_commission_amount' => $platformCommissionAmount,
            'processing_fee_percent' => $feeConfig['processing_fee_percent'],
            'processing_fee_amount' => $processingFeeAmount,
            'total_fee_amount' => $totalFeeAmount,
            'total_amount' => $totalAmount,
            'organizer_net_amount' => $organizerNetAmount,
            'fee_source' => $feeConfig['fee_source'],
            'organizer_plan' => $feeConfig['organizer_plan'],
            'discount_code' => $discountCode ? [
                'id' => $discountCode->id,
                'code' => $discountCode->code,
                'name' => $discountCode->name,
                'discount_type' => $discountCode->discount_type,
                'discount_value' => (float) $discountCode->discount_value,
            ] : null,
        ];
    }

    public function calculateForSelections(Collection $tickets, array $selections, ?EventDiscountCode $discountCode = null): array
    {
        $ticketsById = $tickets->keyBy('id');
        $preliminaryLines = [];
        $platformCommissionPercent = null;
        $processingFeePercent = null;
        $feeSource = null;
        $organizerPlan = null;
        $totalQuantity = 0;
        $eligibleBaseAmount = 0.0;

        foreach ($selections as $selection) {
            /** @var EventTicket $ticket */
            $ticket = $ticketsById->get((int) $selection['ticket_tier_id']);
            $quantity = (int) $selection['quantity'];
            $lineQuote = $this->calculateForTicket($ticket, $quantity);

            $preliminaryLines[] = [
                'ticket_tier_id' => $ticket->id,
                'ticket_tier_name' => $ticket->name,
                'discount_eligible' => $discountCode ? $discountCode->appliesToTicketId((int) $ticket->id) : false,
                ...$lineQuote,
            ];
            $totalQuantity += $quantity;
            $platformCommissionPercent ??= $lineQuote['platform_commission_percent'];
            $processingFeePercent ??= $lineQuote['processing_fee_percent'];
            $feeSource ??= $lineQuote['fee_source'];
            $organizerPlan ??= $lineQuote['organizer_plan'];

            if ($discountCode && $discountCode->appliesToTicketId((int) $ticket->id)) {
                $eligibleBaseAmount += (float) $lineQuote['base_amount'];
            }
        }

        $remainingDiscount = $discountCode ? $discountCode->calculateDiscountForAmount($eligibleBaseAmount) : 0.0;
        $lineItems = [];
        $baseAmount = 0.0;
        $discountAmount = 0.0;
        $discountedBaseAmount = 0.0;
        $totalCredits = 0.0;
        $platformCommissionAmount = 0.0;
        $processingFeeAmount = 0.0;
        $totalFeeAmount = 0.0;
        $totalAmount = 0.0;
        $organizerNetAmount = 0.0;
        $eligibleIndexes = collect($preliminaryLines)
            ->keys()
            ->filter(fn ($index) => $preliminaryLines[$index]['discount_eligible'] === true)
            ->values();

        foreach ($preliminaryLines as $index => $lineQuote) {
            $lineBaseAmount = (float) $lineQuote['base_amount'];
            $lineDiscountAmount = 0.0;

            if ($discountCode && $lineQuote['discount_eligible'] && $remainingDiscount > 0) {
                $isLastEligibleLine = $eligibleIndexes->last() === $index;
                if ($isLastEligibleLine) {
                    $lineDiscountAmount = round(min($lineBaseAmount, $remainingDiscount), 2);
                } elseif ($eligibleBaseAmount > 0) {
                    $lineDiscountAmount = round(min($lineBaseAmount, $remainingDiscount * ($lineBaseAmount / $eligibleBaseAmount)), 2);
                }

                $remainingDiscount = round(max(0, $remainingDiscount - $lineDiscountAmount), 2);
            }

            $lineDiscountedBaseAmount = round(max(0, $lineBaseAmount - $lineDiscountAmount), 2);
            $linePlatformCommissionAmount = round($lineDiscountedBaseAmount * (((float) $lineQuote['platform_commission_percent']) / 100), 2);
            $lineProcessingFeeAmount = round($lineDiscountedBaseAmount * (((float) $lineQuote['processing_fee_percent']) / 100), 2);
            $lineTotalFeeAmount = round($linePlatformCommissionAmount + $lineProcessingFeeAmount, 2);
            $lineTotalAmount = round($lineDiscountedBaseAmount + $lineTotalFeeAmount, 2);
            $lineOrganizerNetAmount = round(max(0, $lineDiscountedBaseAmount - $linePlatformCommissionAmount - $lineProcessingFeeAmount), 2);

            $lineItems[] = [
                ...$lineQuote,
                'discount_amount' => $lineDiscountAmount,
                'discounted_base_amount' => $lineDiscountedBaseAmount,
                'platform_commission_amount' => $linePlatformCommissionAmount,
                'processing_fee_amount' => $lineProcessingFeeAmount,
                'total_fee_amount' => $lineTotalFeeAmount,
                'total_amount' => $lineTotalAmount,
                'organizer_net_amount' => $lineOrganizerNetAmount,
                'discount_code' => $discountCode ? [
                    'id' => $discountCode->id,
                    'code' => $discountCode->code,
                    'name' => $discountCode->name,
                    'discount_type' => $discountCode->discount_type,
                    'discount_value' => (float) $discountCode->discount_value,
                ] : null,
            ];

            $baseAmount += $lineBaseAmount;
            $discountAmount += $lineDiscountAmount;
            $discountedBaseAmount += $lineDiscountedBaseAmount;
            $totalCredits += (float) $lineQuote['total_credits'];
            $platformCommissionAmount += $linePlatformCommissionAmount;
            $processingFeeAmount += $lineProcessingFeeAmount;
            $totalFeeAmount += $lineTotalFeeAmount;
            $totalAmount += $lineTotalAmount;
            $organizerNetAmount += $lineOrganizerNetAmount;
        }

        return [
            'items' => $lineItems,
            'quantity' => $totalQuantity,
            'currency' => 'UGX',
            'unit_price_ugx' => round($totalQuantity > 0 ? $baseAmount / $totalQuantity : 0, 2),
            'unit_price_credits' => round($totalQuantity > 0 ? $totalCredits / $totalQuantity : 0, 2),
            'base_amount' => round($baseAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'discounted_base_amount' => round($discountedBaseAmount, 2),
            'total_credits' => round($totalCredits, 2),
            'platform_commission_percent' => round((float) ($platformCommissionPercent ?? 0), 2),
            'platform_commission_amount' => round($platformCommissionAmount, 2),
            'processing_fee_percent' => round((float) ($processingFeePercent ?? 0), 2),
            'processing_fee_amount' => round($processingFeeAmount, 2),
            'total_fee_amount' => round($totalFeeAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'organizer_net_amount' => round($organizerNetAmount, 2),
            'fee_source' => $feeSource ?? 'event_settings',
            'organizer_plan' => $organizerPlan,
            'discount_code' => $discountCode ? [
                'id' => $discountCode->id,
                'code' => $discountCode->code,
                'name' => $discountCode->name,
                'discount_type' => $discountCode->discount_type,
                'discount_value' => (float) $discountCode->discount_value,
            ] : null,
        ];
    }

    private function resolveFeeConfiguration(?User $organizer): array
    {
        $plan = $organizer?->getActivePlan();
        $planMetadata = $plan?->metadata ?? [];
        $artistCommission = $this->resolveArtistCommissionRate($organizer);

        $platformCommissionPercent = $this->resolvePlanDecimal(
            $plan,
            [
                'event_platform_commission_percent',
                'events.platform_commission_percent',
                'platform_commission_percent',
            ],
            $artistCommission ?? $this->eventSettingsService->getPlatformCommission(),
        );

        $processingFeePercent = $this->resolvePlanDecimal(
            $plan,
            [
                'event_processing_fee_percent',
                'events.processing_fee_percent',
                'processing_fee_percent',
            ],
            $this->eventSettingsService->getProcessingFee(),
        );

        $feeSource = 'event_settings';
        if ($plan && $this->hasAnyConfiguredRate($planMetadata)) {
            $feeSource = 'subscription_plan_metadata';
        } elseif ($artistCommission !== null) {
            $feeSource = 'artist_commission_rate';
        }

        return [
            'platform_commission_percent' => round($platformCommissionPercent, 2),
            'processing_fee_percent' => round($processingFeePercent, 2),
            'fee_source' => $feeSource,
            'organizer_plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'tier' => $plan->tier,
            ] : null,
        ];
    }

    private function resolvePlanDecimal(?SubscriptionPlan $plan, array $keys, float $default): float
    {
        if (! $plan) {
            return round($default, 2);
        }

        foreach ($keys as $key) {
            $value = Arr::get($plan->metadata ?? [], $key);
            if ($value !== null && is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return round($default, 2);
    }

    private function hasAnyConfiguredRate(array $metadata): bool
    {
        return collect([
            'event_platform_commission_percent',
            'events.platform_commission_percent',
            'platform_commission_percent',
            'event_processing_fee_percent',
            'events.processing_fee_percent',
            'processing_fee_percent',
        ])->contains(fn ($key) => Arr::get($metadata, $key) !== null);
    }

    private function resolveArtistCommissionRate(?User $organizer): ?float
    {
        if (! $organizer) {
            return null;
        }

        /** @var Artist|null $artist */
        $artist = $organizer->relationLoaded('artist')
            ? $organizer->artist
            : $organizer->artist()->first();

        if (! $artist || $artist->commission_rate === null) {
            return null;
        }

        return round((float) $artist->commission_rate, 2);
    }
}
