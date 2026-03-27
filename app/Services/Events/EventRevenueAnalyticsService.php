<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventFunnelTouchpoint;
use App\Models\EventTicketCase;
use App\Services\Events\EventAuditLogService;
use Illuminate\Support\Collection;

class EventRevenueAnalyticsService
{
    public function __construct(
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
        private readonly EventAuditLogService $eventAuditLogService,
    ) {}

    public function summarize(Event $event): array
    {
        if (! $event->relationLoaded('attendees')) {
            $event->load('attendees.ticket');
        }

        if (! $event->relationLoaded('tickets')) {
            $event->load('tickets.channelAllocations');
        }

        if (! $event->relationLoaded('interestedUsers')) {
            $event->load('interestedUsers');
        }

        if (! $event->relationLoaded('funnelTouchpoints')) {
            $event->load('funnelTouchpoints');
        }

        if (! $event->relationLoaded('ticketCases')) {
            $event->load('ticketCases');
        }

        $confirmedAttendees = $event->attendees
            ->whereIn('status', [EventAttendee::STATUS_CONFIRMED, EventAttendee::STATUS_ATTENDED])
            ->values();

        $orderFinancials = $this->summarizeOrders($confirmedAttendees);
        $marketingSummary = $this->buildMarketingSummary($confirmedAttendees);
        $funnelSummary = $this->buildFunnelSummary($event, $confirmedAttendees);
        $salesChannels = $this->buildSalesChannelSummary($event, $confirmedAttendees);
        $roiSummary = $this->buildRoiSummary($event, $marketingSummary);
        $payoutSummary = $this->eventPayoutLedgerService->summarizeForEvent($event, $orderFinancials);
        $tierBreakdown = $this->buildTierBreakdown($event, $confirmedAttendees);
        $inventoryAllocations = $this->buildInventoryAllocationSummary($event);
        $dateBreakdown = $this->buildDateBreakdown($confirmedAttendees);
        $ticketsSold = (int) $event->tickets->sum('quantity_sold');
        $interestedCount = (int) $event->interestedUsers->count();
        $checkIns = (int) $event->attendees->whereNotNull('checked_in_at')->count();
        $totalInventory = (int) $event->tickets->sum('quantity_total');
        $conversionRate = $interestedCount > 0 ? round(($ticketsSold / $interestedCount) * 100, 2) : 0.0;
        $sellThroughRate = $totalInventory > 0
            ? round(($ticketsSold / max(1, $totalInventory)) * 100, 2)
            : 0.0;
        $riskSummary = $this->buildRiskSummary($event, $confirmedAttendees, $orderFinancials, $payoutSummary);
        $healthSummary = $this->buildHealthSummary(
            $event,
            $orderFinancials,
            $payoutSummary,
            $marketingSummary,
            $conversionRate,
            $sellThroughRate,
            $ticketsSold,
            $checkIns,
            $totalInventory,
        );

        return [
            'event_id' => $event->id,
            'status' => $event->status,
            'tickets_sold' => $ticketsSold,
            'total_attendees' => (int) $confirmedAttendees->count(),
            'confirmed_orders' => (int) $orderFinancials['order_count'],
            'interested_count' => $interestedCount,
            'check_ins' => $checkIns,
            'revenue' => $orderFinancials['gross_revenue'],
            'gross_revenue' => $orderFinancials['gross_revenue'],
            'customer_paid_total' => $orderFinancials['customer_paid_total'],
            'revenue_credits' => (float) $confirmedAttendees->sum('price_paid_credits'),
            'tesotunes_fee_revenue' => $orderFinancials['tesotunes_fee_revenue'],
            'platform_commission_revenue' => $orderFinancials['platform_commission_revenue'],
            'processing_fee_revenue' => $orderFinancials['processing_fee_revenue'],
            'estimated_organizer_payout' => $orderFinancials['estimated_organizer_payout'],
            'average_order_value' => $orderFinancials['average_order_value'],
            'fee_contract_coverage' => [
                'orders_with_fee_breakdown' => $orderFinancials['orders_with_fee_breakdown'],
                'legacy_orders_without_fee_breakdown' => $orderFinancials['legacy_orders_without_fee_breakdown'],
            ],
            'payouts' => $payoutSummary,
            'marketing' => $marketingSummary,
            'funnel' => $funnelSummary,
            'sales_channels' => $salesChannels,
            'roi' => $roiSummary,
            'inventory_allocations' => $inventoryAllocations,
            'settlements' => $this->buildSettlementSummary($event, $tierBreakdown, $marketingSummary, $payoutSummary),
            'support_cases' => $this->buildSupportCaseSummary($event),
            'audit_log' => $this->buildAuditLogSummary($event),
            'risk' => $riskSummary,
            'health' => $healthSummary,
            'conversion_rate' => $conversionRate,
            'sell_through_rate' => $sellThroughRate,
            'by_tier' => $tierBreakdown,
            'by_date' => $dateBreakdown,
        ];
    }

    public function summarizeRisk(Event $event): array
    {
        if (! $event->relationLoaded('attendees')) {
            $event->load('attendees.ticket');
        }

        if (! $event->relationLoaded('ticketCases')) {
            $event->load('ticketCases');
        }

        if (! $event->relationLoaded('payoutLedgerEntries')) {
            $event->load('payoutLedgerEntries');
        }

        $confirmedAttendees = $event->attendees
            ->whereIn('status', [EventAttendee::STATUS_CONFIRMED, EventAttendee::STATUS_ATTENDED])
            ->values();

        $orderFinancials = $this->summarizeOrders($confirmedAttendees);
        $payoutSummary = $this->eventPayoutLedgerService->summarizeForEvent($event, $orderFinancials);

        return $this->buildRiskSummary($event, $confirmedAttendees, $orderFinancials, $payoutSummary);
    }

    private function summarizeOrders(Collection $attendees): array
    {
        $grossRevenue = 0.0;
        $customerPaidTotal = 0.0;
        $tesotunesFeeRevenue = 0.0;
        $platformCommissionRevenue = 0.0;
        $processingFeeRevenue = 0.0;
        $estimatedOrganizerPayout = 0.0;
        $ordersWithFeeBreakdown = 0;
        $legacyOrdersWithoutFeeBreakdown = 0;

        $processedOrderIds = [];

        foreach ($attendees as $attendee) {
            $metadata = $attendee->attendee_metadata ?? [];
            $orderId = $metadata['order_id'] ?? null;
            $feeBreakdown = $metadata['fee_breakdown'] ?? null;

            if ($orderId && isset($processedOrderIds[$orderId])) {
                continue;
            }

            if ($orderId) {
                $processedOrderIds[$orderId] = true;
            }

            if (is_array($feeBreakdown)) {
                $ordersWithFeeBreakdown++;
                $grossRevenue += (float) ($feeBreakdown['base_amount'] ?? 0);
                $customerPaidTotal += (float) ($feeBreakdown['total_amount'] ?? ($feeBreakdown['base_amount'] ?? 0));
                $tesotunesFeeRevenue += (float) ($feeBreakdown['total_fee_amount'] ?? 0);
                $platformCommissionRevenue += (float) ($feeBreakdown['platform_commission_amount'] ?? 0);
                $processingFeeRevenue += (float) ($feeBreakdown['processing_fee_amount'] ?? 0);
                $estimatedOrganizerPayout += (float) ($feeBreakdown['organizer_net_amount'] ?? ($feeBreakdown['base_amount'] ?? 0));

                continue;
            }

            $legacyOrdersWithoutFeeBreakdown++;
            $grossRevenue += (float) ($attendee->price_paid_ugx ?? $attendee->amount_paid ?? 0);
            $customerPaidTotal += (float) ($attendee->price_paid_ugx ?? $attendee->amount_paid ?? 0);
            $estimatedOrganizerPayout += (float) ($attendee->price_paid_ugx ?? $attendee->amount_paid ?? 0);
        }

        $orderCount = $ordersWithFeeBreakdown + $legacyOrdersWithoutFeeBreakdown;

        return [
            'order_count' => $orderCount,
            'gross_revenue' => round($grossRevenue, 2),
            'customer_paid_total' => round($customerPaidTotal, 2),
            'tesotunes_fee_revenue' => round($tesotunesFeeRevenue, 2),
            'platform_commission_revenue' => round($platformCommissionRevenue, 2),
            'processing_fee_revenue' => round($processingFeeRevenue, 2),
            'estimated_organizer_payout' => round($estimatedOrganizerPayout, 2),
            'average_order_value' => $orderCount > 0 ? round($customerPaidTotal / $orderCount, 2) : 0.0,
            'orders_with_fee_breakdown' => $ordersWithFeeBreakdown,
            'legacy_orders_without_fee_breakdown' => $legacyOrdersWithoutFeeBreakdown,
        ];
    }

    private function buildTierBreakdown(Event $event, Collection $confirmedAttendees): Collection
    {
        return $event->tickets->map(function ($ticket) use ($confirmedAttendees) {
            $tierAttendees = $confirmedAttendees->where('ticket_id', $ticket->id)->values();
            $tierFinancials = $this->summarizeOrders($tierAttendees);

            return [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'sold' => (int) $ticket->quantity_sold,
                'total' => $ticket->quantity_total,
                'revenue' => $tierFinancials['gross_revenue'],
                'estimated_organizer_payout' => $tierFinancials['estimated_organizer_payout'],
                'tesotunes_fee_revenue' => $tierFinancials['tesotunes_fee_revenue'],
                'available' => (int) ($ticket->quantity_available ?? 0),
                'external_allocated' => (int) ($ticket->external_allocated_quantity ?? 0),
            ];
        })->values();
    }

    private function buildDateBreakdown(Collection $confirmedAttendees): Collection
    {
        return $confirmedAttendees
            ->groupBy(fn (EventAttendee $attendee) => optional($attendee->created_at)->toDateString() ?? now()->toDateString())
            ->sortKeys()
            ->map(function (Collection $dayAttendees, string $date) {
                $financials = $this->summarizeOrders($dayAttendees->values());

                return [
                    'date' => $date,
                    'tickets_sold' => (int) $dayAttendees->count(),
                    'revenue' => $financials['gross_revenue'],
                    'customer_paid_total' => $financials['customer_paid_total'],
                    'estimated_organizer_payout' => $financials['estimated_organizer_payout'],
                    'tesotunes_fee_revenue' => $financials['tesotunes_fee_revenue'],
                ];
            })
            ->values();
    }

    private function buildMarketingSummary(Collection $confirmedAttendees): array
    {
        $sources = [];
        $attributedOrders = 0;
        $attributedRevenue = 0.0;

        foreach ($this->groupAttendeesByOrder($confirmedAttendees) as $orderAttendees) {
            /** @var EventAttendee $primaryAttendee */
            $primaryAttendee = $orderAttendees->first();
            $metadata = $primaryAttendee->attendee_metadata ?? [];
            $attribution = is_array($metadata['attribution'] ?? null) ? $metadata['attribution'] : [];
            $label = $this->resolveAttributionLabel($attribution);

            if (! $label) {
                continue;
            }

            $financials = $this->summarizeOrders($orderAttendees);
            $attributedOrders++;
            $attributedRevenue += $financials['gross_revenue'];

            $sources[$label] ??= [
                'source' => $label,
                'channel' => $attribution['channel'] ?? null,
                'campaign_code' => $attribution['campaign_code'] ?? ($attribution['utm_campaign'] ?? null),
                'referral_code' => $attribution['referral_code'] ?? ($attribution['promoter_code'] ?? null),
                'orders' => 0,
                'tickets_sold' => 0,
                'gross_revenue' => 0.0,
                'customer_paid_total' => 0.0,
                'estimated_organizer_payout' => 0.0,
                'tesotunes_fee_revenue' => 0.0,
            ];

            $sources[$label]['orders']++;
            $sources[$label]['tickets_sold'] += (int) $orderAttendees->sum(fn (EventAttendee $attendee) => $attendee->quantity ?? 1);
            $sources[$label]['gross_revenue'] += $financials['gross_revenue'];
            $sources[$label]['customer_paid_total'] += $financials['customer_paid_total'];
            $sources[$label]['estimated_organizer_payout'] += $financials['estimated_organizer_payout'];
            $sources[$label]['tesotunes_fee_revenue'] += $financials['tesotunes_fee_revenue'];
        }

        $topSources = collect($sources)
            ->map(fn (array $source) => [
                ...$source,
                'gross_revenue' => round($source['gross_revenue'], 2),
                'customer_paid_total' => round($source['customer_paid_total'], 2),
                'estimated_organizer_payout' => round($source['estimated_organizer_payout'], 2),
                'tesotunes_fee_revenue' => round($source['tesotunes_fee_revenue'], 2),
            ])
            ->sortByDesc('gross_revenue')
            ->values()
            ->all();

        return [
            'attributed_orders' => $attributedOrders,
            'unattributed_orders' => max(0, $this->summarizeOrders($confirmedAttendees)['order_count'] - $attributedOrders),
            'attributed_revenue' => round($attributedRevenue, 2),
            'top_sources' => $topSources,
        ];
    }

    private function buildSalesChannelSummary(Event $event, Collection $confirmedAttendees): array
    {
        $channels = [
            'tesotunes_native' => $this->makeSalesChannelBucket('tesotunes_native', 'Tesotunes-native'),
            'tracked_promo' => $this->makeSalesChannelBucket('tracked_promo', 'Tracked promo'),
            'manual_offline' => $this->makeSalesChannelBucket('manual_offline', 'Manual / offline'),
            'external' => $this->makeSalesChannelBucket('external', 'External'),
        ];

        foreach ($this->groupAttendeesByOrder($confirmedAttendees) as $orderAttendees) {
            /** @var EventAttendee $primaryAttendee */
            $primaryAttendee = $orderAttendees->first();
            $metadata = $primaryAttendee->attendee_metadata ?? [];
            $channelKey = $this->resolveSalesChannelKey($event, $metadata);
            $financials = $this->summarizeOrders($orderAttendees);
            $ticketsSold = (int) $orderAttendees->sum(fn (EventAttendee $attendee) => $attendee->quantity ?? 1);

            $channels[$channelKey]['orders']++;
            $channels[$channelKey]['tickets_sold'] += $ticketsSold;
            $channels[$channelKey]['gross_revenue'] += $financials['gross_revenue'];
            $channels[$channelKey]['customer_paid_total'] += $financials['customer_paid_total'];
            $channels[$channelKey]['estimated_organizer_payout'] += $financials['estimated_organizer_payout'];
            $channels[$channelKey]['tesotunes_fee_revenue'] += $financials['tesotunes_fee_revenue'];
        }

        $totalOrders = array_sum(array_map(fn (array $channel) => $channel['orders'], $channels));

        return [
            'channels' => collect($channels)
                ->map(function (array $channel) use ($totalOrders) {
                    $channel['gross_revenue'] = round($channel['gross_revenue'], 2);
                    $channel['customer_paid_total'] = round($channel['customer_paid_total'], 2);
                    $channel['estimated_organizer_payout'] = round($channel['estimated_organizer_payout'], 2);
                    $channel['tesotunes_fee_revenue'] = round($channel['tesotunes_fee_revenue'], 2);
                    $channel['order_share_percent'] = $totalOrders > 0
                        ? round(($channel['orders'] / $totalOrders) * 100, 2)
                        : 0.0;

                    return $channel;
                })
                ->sortByDesc('gross_revenue')
                ->values()
                ->all(),
        ];
    }

    private function buildInventoryAllocationSummary(Event $event): array
    {
        $byTier = $event->tickets->map(function ($ticket) {
            return [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'quantity_total' => $ticket->quantity_total,
                'quantity_sold' => (int) ($ticket->quantity_sold ?? 0),
                'quantity_reserved' => (int) ($ticket->quantity_reserved ?? 0),
                'quantity_external_allocated' => (int) ($ticket->external_allocated_quantity ?? 0),
                'available' => (int) ($ticket->quantity_available ?? 0),
            ];
        })->values();

        return [
            'external_allocated_total' => (int) $byTier->sum('quantity_external_allocated'),
            'by_tier' => $byTier->all(),
        ];
    }

    private function buildSettlementSummary(
        Event $event,
        Collection $tierBreakdown,
        array $marketingSummary,
        array $payoutSummary,
    ): array {
        $entries = $this->eventPayoutLedgerService->entriesForEvent($event);

        $byPayoutCycle = $entries
            ->groupBy(function ($entry) {
                return optional($entry->paid_out_at ?? $entry->payout_ready_at ?? $entry->occurred_at)?->toDateString()
                    ?? 'unassigned';
            })
            ->map(function (Collection $group, string $cycleDate) {
                return [
                    'cycle_date' => $cycleDate === 'unassigned' ? null : $cycleDate,
                    'entry_count' => $group->count(),
                    'gross_revenue' => round((float) $group->sum('gross_revenue'), 2),
                    'customer_paid_total' => round((float) $group->sum('customer_paid_total'), 2),
                    'tesotunes_fee_revenue' => round((float) $group->sum('tesotunes_fee_revenue'), 2),
                    'organizer_net_amount' => round((float) $group->sum('organizer_net_amount'), 2),
                    'dominant_status' => (string) $group->groupBy('payout_status')->sortByDesc->count()->keys()->first(),
                ];
            })
            ->sortByDesc(fn (array $cycle) => $cycle['cycle_date'] ?? '')
            ->values();

        $byCampaign = collect($marketingSummary['top_sources'] ?? [])
            ->map(fn (array $source) => [
                'label' => $source['source'],
                'channel' => $source['channel'] ?? null,
                'campaign_code' => $source['campaign_code'] ?? null,
                'referral_code' => $source['referral_code'] ?? null,
                'orders' => (int) ($source['orders'] ?? 0),
                'tickets_sold' => (int) ($source['tickets_sold'] ?? 0),
                'gross_revenue' => (float) ($source['gross_revenue'] ?? 0),
                'customer_paid_total' => (float) ($source['customer_paid_total'] ?? 0),
                'tesotunes_fee_revenue' => (float) ($source['tesotunes_fee_revenue'] ?? 0),
                'organizer_net_amount' => (float) ($source['estimated_organizer_payout'] ?? 0),
            ])
            ->values();

        return [
            'event_totals' => [
                'gross_revenue' => round((float) $tierBreakdown->sum('revenue'), 2),
                'organizer_net_amount' => round((float) (
                    ($payoutSummary['pending_balance'] ?? 0)
                    + ($payoutSummary['ready_balance'] ?? 0)
                    + ($payoutSummary['settled_balance'] ?? 0)
                ), 2),
                'settled_balance' => round((float) ($payoutSummary['settled_balance'] ?? 0), 2),
                'failed_balance' => round((float) ($payoutSummary['failed_balance'] ?? 0), 2),
            ],
            'by_tier' => $tierBreakdown->map(fn (array $tier) => [
                'tier' => $tier['name'],
                'sold' => (int) $tier['sold'],
                'gross_revenue' => (float) $tier['revenue'],
                'organizer_net_amount' => (float) $tier['estimated_organizer_payout'],
                'tesotunes_fee_revenue' => (float) $tier['tesotunes_fee_revenue'],
            ])->values(),
            'by_campaign' => $byCampaign,
            'by_payout_cycle' => $byPayoutCycle,
        ];
    }

    private function buildHealthSummary(
        Event $event,
        array $orderFinancials,
        array $payoutSummary,
        array $marketingSummary,
        float $conversionRate,
        float $sellThroughRate,
        int $ticketsSold,
        int $checkIns,
        int $totalInventory,
    ): array {
        $score = 100;
        $issues = [];

        if (blank(data_get($event->contact_info, 'support_email')) && blank(data_get($event->contact_info, 'support_phone'))) {
            $score -= 15;
            $issues[] = [
                'key' => 'missing_support_contact',
                'severity' => 'medium',
                'label' => 'Missing support contact',
                'detail' => 'Buyers do not have a clear event support channel.',
            ];
        }

        if (! data_get($event->resolveOrganizerUser(), 'payment_method') && ($orderFinancials['gross_revenue'] ?? 0) > 0) {
            $score -= 20;
            $issues[] = [
                'key' => 'missing_payout_setup',
                'severity' => 'high',
                'label' => 'Organizer payout setup incomplete',
                'detail' => 'Revenue exists, but the organizer payout method is not fully configured.',
            ];
        }

        if (($payoutSummary['held_balance'] ?? 0) > 0) {
            $score -= 10;
            $issues[] = [
                'key' => 'payouts_on_hold',
                'severity' => 'medium',
                'label' => 'Payouts currently on hold',
                'detail' => 'Admin review is blocking a portion of organizer payout settlement.',
            ];
        }

        if (($orderFinancials['legacy_orders_without_fee_breakdown'] ?? 0) > 0) {
            $score -= 10;
            $issues[] = [
                'key' => 'legacy_fee_contract_gap',
                'severity' => 'medium',
                'label' => 'Legacy orders without fee breakdown',
                'detail' => 'Some historical orders are missing the locked fee contract metadata.',
            ];
        }

        if ($totalInventory > 0 && $ticketsSold === 0 && $event->status === 'published') {
            $score -= 15;
            $issues[] = [
                'key' => 'no_sales_yet',
                'severity' => 'medium',
                'label' => 'Published with no sales yet',
                'detail' => 'This event is live but has not recorded ticket sales yet.',
            ];
        }

        if ($ticketsSold > 0 && $conversionRate < 5) {
            $score -= 10;
            $issues[] = [
                'key' => 'weak_conversion',
                'severity' => 'medium',
                'label' => 'Weak conversion funnel',
                'detail' => 'Interest is not converting into enough paid orders yet.',
            ];
        }

        if ($ticketsSold > 0 && $checkIns === 0 && $event->starts_at && $event->starts_at->isPast()) {
            $score -= 10;
            $issues[] = [
                'key' => 'missing_check_ins',
                'severity' => 'low',
                'label' => 'No check-ins recorded',
                'detail' => 'The event has sales, but door operations have not logged attendee check-ins.',
            ];
        }

        if (($marketingSummary['attributed_orders'] ?? 0) === 0 && $ticketsSold > 0) {
            $score -= 5;
            $issues[] = [
                'key' => 'missing_attribution',
                'severity' => 'low',
                'label' => 'Sales lack attribution',
                'detail' => 'Orders are coming in without tracked promotion sources.',
            ];
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'grade' => $score >= 85 ? 'strong' : ($score >= 65 ? 'watch' : 'at_risk'),
            'issues' => $issues,
            'summary' => [
                'conversion_rate' => $conversionRate,
                'sell_through_rate' => $sellThroughRate,
                'held_balance' => (float) ($payoutSummary['held_balance'] ?? 0),
                'legacy_orders_without_fee_breakdown' => (int) ($orderFinancials['legacy_orders_without_fee_breakdown'] ?? 0),
            ],
        ];
    }

    private function buildRiskSummary(
        Event $event,
        Collection $confirmedAttendees,
        array $orderFinancials,
        array $payoutSummary,
    ): array {
        $duplicateOverrideCount = (int) $confirmedAttendees
            ->filter(fn (EventAttendee $attendee) => (bool) data_get($attendee->attendee_metadata, 'last_check_in_override', false))
            ->count();

        $manualOfflineTicketCount = (int) $confirmedAttendees
            ->filter(function (EventAttendee $attendee) {
                $ticketSource = (string) data_get($attendee->attendee_metadata, 'ticket_source', '');

                return in_array($ticketSource, ['manual_offline', 'printed_ticket', 'door_sale', 'phone_booking', 'complimentary'], true);
            })
            ->count();

        $printedTicketCount = (int) $confirmedAttendees
            ->filter(fn (EventAttendee $attendee) => (bool) data_get($attendee->attendee_metadata, 'printed_ticket_import', false))
            ->count();

        $chargebackReviewCases = (int) $event->ticketCases
            ->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)
            ->where('escalation_status', EventTicketCase::ESCALATION_REVIEW)
            ->count();

        $chargebackExposureAmount = round((float) $event->ticketCases
            ->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)
            ->where('escalation_status', EventTicketCase::ESCALATION_REVIEW)
            ->sum('requested_refund_amount'), 2);

        $riskScore = 0;
        $signals = [];

        if ($duplicateOverrideCount > 0) {
            $riskScore += min(25, $duplicateOverrideCount * 8);
            $signals[] = [
                'key' => 'duplicate_check_in_overrides',
                'severity' => $duplicateOverrideCount >= 2 ? 'high' : 'medium',
                'label' => 'Duplicate check-in overrides',
                'detail' => 'Door staff recorded duplicate ticket scans that required override handling.',
                'count' => $duplicateOverrideCount,
            ];
        }

        if ($manualOfflineTicketCount > 0) {
            $riskScore += min(20, $manualOfflineTicketCount * 3);
            $signals[] = [
                'key' => 'manual_offline_volume',
                'severity' => $manualOfflineTicketCount >= 5 ? 'high' : 'medium',
                'label' => 'Manual or offline ticket volume',
                'detail' => 'A portion of the event attendance came through offline or manually reconciled ticketing.',
                'count' => $manualOfflineTicketCount,
            ];
        }

        if ($printedTicketCount > 0) {
            $riskScore += min(15, $printedTicketCount * 2);
            $signals[] = [
                'key' => 'printed_ticket_imports',
                'severity' => $printedTicketCount >= 5 ? 'medium' : 'low',
                'label' => 'Printed ticket imports present',
                'detail' => 'Printed ticket batches were used and should be reviewed alongside door validation notes.',
                'count' => $printedTicketCount,
            ];
        }

        if (($payoutSummary['failed_balance'] ?? 0) > 0) {
            $riskScore += 20;
            $signals[] = [
                'key' => 'failed_payout_balance',
                'severity' => 'high',
                'label' => 'Failed payout balance',
                'detail' => 'Some organizer payout value is currently in a failed state.',
                'amount' => (float) ($payoutSummary['failed_balance'] ?? 0),
            ];
        }

        if (($payoutSummary['held_balance'] ?? 0) > 0) {
            $riskScore += 10;
            $signals[] = [
                'key' => 'held_payout_balance',
                'severity' => 'medium',
                'label' => 'Held payout balance',
                'detail' => 'Admin has placed part of the payout ledger on hold for review.',
                'amount' => (float) ($payoutSummary['held_balance'] ?? 0),
            ];
        }

        if ($chargebackReviewCases > 0) {
            $riskScore += min(25, $chargebackReviewCases * 10);
            $signals[] = [
                'key' => 'chargeback_review',
                'severity' => $chargebackReviewCases >= 2 ? 'high' : 'medium',
                'label' => 'Chargeback review cases',
                'detail' => 'Payment disputes are waiting for review and may affect payout confidence.',
                'count' => $chargebackReviewCases,
                'amount' => $chargebackExposureAmount,
            ];
        }

        if (($orderFinancials['legacy_orders_without_fee_breakdown'] ?? 0) > 0) {
            $riskScore += 10;
            $signals[] = [
                'key' => 'legacy_fee_contract_gap',
                'severity' => 'medium',
                'label' => 'Legacy orders missing fee contract',
                'detail' => 'Some orders do not have the newer fee breakdown metadata locked in.',
                'count' => (int) ($orderFinancials['legacy_orders_without_fee_breakdown'] ?? 0),
            ];
        }

        $riskScore = max(0, min(100, $riskScore));

        return [
            'score' => $riskScore,
            'level' => $riskScore >= 60 ? 'high' : ($riskScore >= 25 ? 'watch' : 'low'),
            'duplicate_check_in_overrides' => $duplicateOverrideCount,
            'manual_offline_ticket_count' => $manualOfflineTicketCount,
            'printed_ticket_count' => $printedTicketCount,
            'chargeback_review_cases' => $chargebackReviewCases,
            'chargeback_exposure_amount' => $chargebackExposureAmount,
            'failed_payout_balance' => round((float) ($payoutSummary['failed_balance'] ?? 0), 2),
            'held_payout_balance' => round((float) ($payoutSummary['held_balance'] ?? 0), 2),
            'legacy_orders_without_fee_breakdown' => (int) ($orderFinancials['legacy_orders_without_fee_breakdown'] ?? 0),
            'signals' => $signals,
        ];
    }

    private function buildAuditLogSummary(Event $event): array
    {
        $entries = $this->eventAuditLogService->recentForEvent($event, 12);

        return [
            'total_entries' => $entries->count(),
            'recent_entries' => $entries->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'actor' => $entry->relationLoaded('user') && $entry->user ? $entry->user->name : null,
                    'created_at' => $entry->created_at?->toIso8601String(),
                    'old_values' => $entry->old_values ?? [],
                    'new_values' => $entry->new_values ?? [],
                ];
            })->values()->all(),
        ];
    }

    private function buildRoiSummary(Event $event, array $marketingSummary): array
    {
        $spendEntries = collect(data_get($event->marketing_settings, 'campaign_spend', []))
            ->filter(fn ($entry) => is_array($entry))
            ->map(function (array $entry) {
                $label = trim((string) ($entry['label'] ?? ''));

                return [
                    'key' => $this->normalizeAnalyticsKey((string) ($entry['key'] ?? $label)),
                    'label' => $label !== '' ? $label : 'Campaign Spend',
                    'spend' => round((float) ($entry['amount'] ?? 0), 2),
                    'notes' => $entry['notes'] ?? null,
                    'currency' => $entry['currency'] ?? 'UGX',
                ];
            })
            ->keyBy('key');

        $sourceRows = collect($marketingSummary['top_sources'] ?? [])
            ->map(function (array $source) use ($spendEntries) {
                $key = $this->normalizeAnalyticsKey((string) ($source['source'] ?? ''));
                $spendEntry = $spendEntries->get($key);
                $spend = (float) ($spendEntry['spend'] ?? 0);
                $organizerPayout = (float) ($source['estimated_organizer_payout'] ?? 0);
                $grossRevenue = (float) ($source['gross_revenue'] ?? 0);

                return [
                    'key' => $key,
                    'label' => $source['source'] ?? ($spendEntry['label'] ?? 'Tracked Source'),
                    'channel' => $source['channel'] ?? null,
                    'campaign_code' => $source['campaign_code'] ?? null,
                    'referral_code' => $source['referral_code'] ?? null,
                    'orders' => (int) ($source['orders'] ?? 0),
                    'tickets_sold' => (int) ($source['tickets_sold'] ?? 0),
                    'spend' => round($spend, 2),
                    'gross_revenue' => round($grossRevenue, 2),
                    'customer_paid_total' => round((float) ($source['customer_paid_total'] ?? 0), 2),
                    'estimated_organizer_payout' => round($organizerPayout, 2),
                    'tesotunes_fee_revenue' => round((float) ($source['tesotunes_fee_revenue'] ?? 0), 2),
                    'net_profit' => round($organizerPayout - $spend, 2),
                    'roas' => $spend > 0 ? round($grossRevenue / $spend, 2) : null,
                    'payout_roi_percent' => $spend > 0 ? round((($organizerPayout - $spend) / $spend) * 100, 2) : null,
                    'notes' => $spendEntry['notes'] ?? null,
                ];
            });

        $spendOnlyRows = $spendEntries
            ->reject(fn (array $entry, string $key) => $sourceRows->contains(fn (array $row) => $row['key'] === $key))
            ->map(fn (array $entry, string $key) => [
                'key' => $key,
                'label' => $entry['label'],
                'channel' => null,
                'campaign_code' => null,
                'referral_code' => null,
                'orders' => 0,
                'tickets_sold' => 0,
                'spend' => (float) $entry['spend'],
                'gross_revenue' => 0.0,
                'customer_paid_total' => 0.0,
                'estimated_organizer_payout' => 0.0,
                'tesotunes_fee_revenue' => 0.0,
                'net_profit' => round(0 - (float) $entry['spend'], 2),
                'roas' => 0.0,
                'payout_roi_percent' => -100.0,
                'notes' => $entry['notes'] ?? null,
            ]);

        $rows = $sourceRows
            ->concat($spendOnlyRows)
            ->sortByDesc(fn (array $row) => $row['gross_revenue'])
            ->values();

        return [
            'total_spend' => round((float) $rows->sum('spend'), 2),
            'total_gross_revenue' => round((float) $rows->sum('gross_revenue'), 2),
            'total_organizer_payout' => round((float) $rows->sum('estimated_organizer_payout'), 2),
            'total_net_profit' => round((float) $rows->sum('net_profit'), 2),
            'tracked_sources' => $rows->count(),
            'by_source' => $rows->all(),
        ];
    }

    private function buildSupportCaseSummary(Event $event): array
    {
        $cases = $event->ticketCases instanceof Collection
            ? $event->ticketCases
            : collect();

        return [
            'open' => (int) $cases->where('status', EventTicketCase::STATUS_OPEN)->count(),
            'approved' => (int) $cases->where('status', EventTicketCase::STATUS_APPROVED)->count(),
            'rejected' => (int) $cases->where('status', EventTicketCase::STATUS_REJECTED)->count(),
            'refund_requests' => (int) $cases->where('case_type', EventTicketCase::TYPE_REFUND_REQUEST)->count(),
            'payment_disputes' => (int) $cases->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)->count(),
            'open_payment_disputes' => (int) $cases
                ->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)
                ->where('status', EventTicketCase::STATUS_OPEN)
                ->count(),
            'chargeback_review_cases' => (int) $cases
                ->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)
                ->where('escalation_status', EventTicketCase::ESCALATION_REVIEW)
                ->count(),
            'chargeback_exposure_amount' => round((float) $cases
                ->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)
                ->whereIn('status', [EventTicketCase::STATUS_OPEN, EventTicketCase::STATUS_APPROVED])
                ->sum('requested_refund_amount'), 2),
            'approved_refund_amount' => round((float) $cases->where('status', EventTicketCase::STATUS_APPROVED)->sum('approved_refund_amount'), 2),
        ];
    }

    private function buildFunnelSummary(Event $event, Collection $confirmedAttendees): array
    {
        $rows = [];

        foreach ($event->funnelTouchpoints as $touchpoint) {
            $label = $touchpoint->source_label ?: 'direct-native';
            $rows[$label] ??= $this->makeFunnelBucket($label, [
                'channel' => $touchpoint->channel,
                'campaign_code' => $touchpoint->campaign_code,
                'referral_code' => $touchpoint->referral_code,
            ]);

            if ($touchpoint->stage === EventFunnelTouchpoint::STAGE_VISIT) {
                $rows[$label]['visits']++;
            }

            if ($touchpoint->stage === EventFunnelTouchpoint::STAGE_CHECKOUT_START) {
                $rows[$label]['checkout_starts']++;
            }
        }

        foreach ($this->groupAttendeesByOrder($confirmedAttendees) as $orderAttendees) {
            /** @var EventAttendee $primaryAttendee */
            $primaryAttendee = $orderAttendees->first();
            $metadata = $primaryAttendee->attendee_metadata ?? [];
            $attribution = is_array($metadata['attribution'] ?? null) ? $metadata['attribution'] : [];
            $label = $this->resolveAttributionLabel($attribution) ?: 'direct-native';

            $rows[$label] ??= $this->makeFunnelBucket($label, [
                'channel' => $attribution['channel'] ?? null,
                'campaign_code' => $attribution['campaign_code'] ?? ($attribution['utm_campaign'] ?? null),
                'referral_code' => $attribution['referral_code'] ?? ($attribution['promoter_code'] ?? null),
            ]);

            $rows[$label]['paid_orders']++;
            $rows[$label]['tickets_sold'] += (int) $orderAttendees->sum(fn (EventAttendee $attendee) => $attendee->quantity ?? 1);
        }

        $bySource = collect($rows)
            ->map(function (array $row) {
                $row['visit_to_checkout_rate'] = $row['visits'] > 0
                    ? round(($row['checkout_starts'] / $row['visits']) * 100, 2)
                    : 0.0;
                $row['checkout_to_order_rate'] = $row['checkout_starts'] > 0
                    ? round(($row['paid_orders'] / $row['checkout_starts']) * 100, 2)
                    : 0.0;
                $row['visit_to_order_rate'] = $row['visits'] > 0
                    ? round(($row['paid_orders'] / $row['visits']) * 100, 2)
                    : 0.0;

                return $row;
            })
            ->sortByDesc(fn (array $row) => $row['paid_orders'] > 0 ? $row['paid_orders'] : $row['visits'])
            ->values();

        return [
            'totals' => [
                'visits' => (int) $bySource->sum('visits'),
                'checkout_starts' => (int) $bySource->sum('checkout_starts'),
                'paid_orders' => (int) $bySource->sum('paid_orders'),
                'tickets_sold' => (int) $bySource->sum('tickets_sold'),
            ],
            'by_source' => $bySource->all(),
        ];
    }

    private function resolveAttributionLabel(array $attribution): ?string
    {
        foreach ([
            'campaign_code',
            'promoter_code',
            'referral_code',
            'utm_campaign',
            'utm_source',
            'source',
            'channel',
        ] as $key) {
            $value = $attribution[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function groupAttendeesByOrder(Collection $attendees): Collection
    {
        return $attendees
            ->groupBy(function (EventAttendee $attendee) {
                $metadata = $attendee->attendee_metadata ?? [];

                return $metadata['order_id'] ?? 'attendee:'.$attendee->id;
            })
            ->values();
    }

    private function makeSalesChannelBucket(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'orders' => 0,
            'tickets_sold' => 0,
            'gross_revenue' => 0.0,
            'customer_paid_total' => 0.0,
            'estimated_organizer_payout' => 0.0,
            'tesotunes_fee_revenue' => 0.0,
            'order_share_percent' => 0.0,
        ];
    }

    private function makeFunnelBucket(string $label, array $meta = []): array
    {
        return [
            'label' => $label,
            'channel' => $meta['channel'] ?? null,
            'campaign_code' => $meta['campaign_code'] ?? null,
            'referral_code' => $meta['referral_code'] ?? null,
            'visits' => 0,
            'checkout_starts' => 0,
            'paid_orders' => 0,
            'tickets_sold' => 0,
            'visit_to_checkout_rate' => 0.0,
            'checkout_to_order_rate' => 0.0,
            'visit_to_order_rate' => 0.0,
        ];
    }

    private function normalizeAnalyticsKey(string $value): string
    {
        return trim((string) str($value)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-'));
    }

    private function resolveSalesChannelKey(Event $event, array $metadata): string
    {
        $attribution = is_array($metadata['attribution'] ?? null) ? $metadata['attribution'] : [];
        if ($this->resolveAttributionLabel($attribution)) {
            return 'tracked_promo';
        }

        $salesChannel = strtolower((string) ($metadata['sales_channel'] ?? $metadata['ticket_source'] ?? ''));
        if (in_array($salesChannel, ['manual_offline', 'manual', 'offline', 'printed'], true)
            || (bool) ($metadata['is_manual_ticket'] ?? false)
            || (bool) ($metadata['offline_sale'] ?? false)) {
            return 'manual_offline';
        }

        if (in_array($salesChannel, ['external', 'external_only'], true)
            || (bool) ($metadata['external_ticketing'] ?? false)
            || $event->ticketing_mode === Event::TICKETING_MODE_EXTERNAL_ONLY) {
            return 'external';
        }

        return 'tesotunes_native';
    }
}
