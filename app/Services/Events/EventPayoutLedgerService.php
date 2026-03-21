<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventPayoutLedgerEntry;
use App\Models\Payment;
use Illuminate\Support\Collection;

class EventPayoutLedgerService
{
    public function syncPendingForPayment(Payment $payment): EventPayoutLedgerEntry
    {
        return $this->upsertFromPayment($payment, EventPayoutLedgerEntry::STATUS_PENDING);
    }

    public function markPaymentReady(Payment $payment): ?EventPayoutLedgerEntry
    {
        if ($payment->payment_type !== 'ticket_purchase') {
            return null;
        }

        return $this->upsertFromPayment($payment, EventPayoutLedgerEntry::STATUS_READY, [
            'payout_ready_at' => now(),
            'failed_at' => null,
        ]);
    }

    public function markPaymentFailed(Payment $payment, ?string $reason = null): ?EventPayoutLedgerEntry
    {
        if ($payment->payment_type !== 'ticket_purchase') {
            return null;
        }

        return $this->upsertFromPayment($payment, EventPayoutLedgerEntry::STATUS_FAILED, [
            'failed_at' => now(),
            'metadata' => array_filter([
                ...($this->resolveEntryMetadata($payment)['metadata'] ?? []),
                'failure_reason' => $reason,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public function entriesForEvent(Event $event): Collection
    {
        if ($event->relationLoaded('payoutLedgerEntries')) {
            return $event->payoutLedgerEntries;
        }

        return $event->payoutLedgerEntries()->orderByDesc('occurred_at')->get();
    }

    public function summarizeForEvent(Event $event, ?array $financials = null): array
    {
        $entries = $this->entriesForEvent($event);

        if ($entries->isEmpty()) {
            $financials ??= [
                'estimated_organizer_payout' => 0.0,
            ];

            return [
                'pending_balance' => 0.0,
                'ready_balance' => round((float) ($financials['estimated_organizer_payout'] ?? 0), 2),
                'settled_balance' => 0.0,
                'failed_balance' => 0.0,
                'entry_count' => 0,
                'status_breakdown' => [
                    EventPayoutLedgerEntry::STATUS_PENDING => 0,
                    EventPayoutLedgerEntry::STATUS_READY => $financials['estimated_organizer_payout'] > 0 ? 1 : 0,
                    EventPayoutLedgerEntry::STATUS_PAID => 0,
                    EventPayoutLedgerEntry::STATUS_FAILED => 0,
                ],
                'latest_ready_at' => null,
                'latest_paid_out_at' => null,
            ];
        }

        return [
            'pending_balance' => round((float) $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_PENDING)->sum('organizer_net_amount'), 2),
            'ready_balance' => round((float) $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_READY)->sum('organizer_net_amount'), 2),
            'settled_balance' => round((float) $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_PAID)->sum('organizer_net_amount'), 2),
            'failed_balance' => round((float) $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_FAILED)->sum('organizer_net_amount'), 2),
            'entry_count' => $entries->count(),
            'status_breakdown' => [
                EventPayoutLedgerEntry::STATUS_PENDING => $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_PENDING)->count(),
                EventPayoutLedgerEntry::STATUS_READY => $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_READY)->count(),
                EventPayoutLedgerEntry::STATUS_PAID => $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_PAID)->count(),
                EventPayoutLedgerEntry::STATUS_FAILED => $entries->where('payout_status', EventPayoutLedgerEntry::STATUS_FAILED)->count(),
            ],
            'latest_ready_at' => optional($entries->where('payout_status', EventPayoutLedgerEntry::STATUS_READY)->sortByDesc('payout_ready_at')->first())->payout_ready_at?->toIso8601String(),
            'latest_paid_out_at' => optional($entries->where('payout_status', EventPayoutLedgerEntry::STATUS_PAID)->sortByDesc('paid_out_at')->first())->paid_out_at?->toIso8601String(),
        ];
    }

    public function exportRowsForEvent(Event $event): Collection
    {
        $entries = $this->entriesForEvent($event);

        if ($entries->isNotEmpty()) {
            return $entries->map(function (EventPayoutLedgerEntry $entry) {
                return [
                    'order_id' => $entry->order_id,
                    'payment_reference' => $entry->payment_reference,
                    'payout_status' => $entry->payout_status,
                    'ticket_quantity' => (int) $entry->ticket_quantity,
                    'gross_revenue' => (float) $entry->gross_revenue,
                    'customer_paid_total' => (float) $entry->customer_paid_total,
                    'tesotunes_fee_revenue' => (float) $entry->tesotunes_fee_revenue,
                    'platform_commission_amount' => (float) $entry->platform_commission_amount,
                    'processing_fee_amount' => (float) $entry->processing_fee_amount,
                    'organizer_net_amount' => (float) $entry->organizer_net_amount,
                    'fee_source' => $entry->fee_source,
                    'attribution_label' => $entry->attribution_label,
                    'occurred_at' => $entry->occurred_at?->toIso8601String(),
                    'payout_ready_at' => $entry->payout_ready_at?->toIso8601String(),
                    'paid_out_at' => $entry->paid_out_at?->toIso8601String(),
                ];
            });
        }

        return $this->buildLegacyRowsFromAttendees($event);
    }

    private function upsertFromPayment(Payment $payment, string $status, array $overrides = []): ?EventPayoutLedgerEntry
    {
        $attributes = $this->resolveEntryAttributes($payment);
        if (! $attributes) {
            return null;
        }

        $payload = array_merge($attributes, $overrides, [
            'payout_status' => $status,
        ]);

        return EventPayoutLedgerEntry::updateOrCreate(
            ['payment_id' => $payment->id],
            $payload,
        );
    }

    private function resolveEntryAttributes(Payment $payment): ?array
    {
        if ($payment->payment_type !== 'ticket_purchase') {
            return null;
        }

        $metadata = $payment->metadata ?? [];
        $event = Event::find($metadata['event_id'] ?? $payment->payable_id);
        if (! $event) {
            return null;
        }

        $feeBreakdown = is_array($metadata['fee_breakdown'] ?? null) ? $metadata['fee_breakdown'] : [];
        $resolved = $this->resolveEntryMetadata($payment);

        return [
            'event_id' => $event->id,
            'organizer_id' => $event->organizer_id,
            'order_id' => $metadata['order_id'] ?? $payment->transaction_reference,
            'payment_reference' => $payment->payment_reference,
            'currency' => $payment->currency ?? 'UGX',
            'ticket_quantity' => (int) ($metadata['quantity'] ?? 0),
            'gross_revenue' => (float) ($feeBreakdown['base_amount'] ?? max(0, (float) $payment->amount - (float) ($feeBreakdown['total_fee_amount'] ?? 0))),
            'customer_paid_total' => (float) ($feeBreakdown['total_amount'] ?? $payment->amount ?? 0),
            'tesotunes_fee_revenue' => (float) ($feeBreakdown['total_fee_amount'] ?? 0),
            'platform_commission_amount' => (float) ($feeBreakdown['platform_commission_amount'] ?? 0),
            'processing_fee_amount' => (float) ($feeBreakdown['processing_fee_amount'] ?? 0),
            'organizer_net_amount' => (float) ($feeBreakdown['organizer_net_amount'] ?? max(0, (float) ($feeBreakdown['base_amount'] ?? 0) - (float) ($feeBreakdown['total_fee_amount'] ?? 0))),
            'fee_source' => $feeBreakdown['fee_source'] ?? null,
            'attribution_label' => $resolved['attribution_label'],
            'attribution' => $resolved['attribution'],
            'metadata' => $resolved['metadata'],
            'occurred_at' => $payment->completed_at ?? $payment->created_at,
        ];
    }

    private function resolveEntryMetadata(Payment $payment): array
    {
        $metadata = $payment->metadata ?? [];
        $attribution = is_array($metadata['attribution'] ?? null) ? $metadata['attribution'] : [];
        $label = null;

        foreach (['campaign_code', 'promoter_code', 'referral_code', 'utm_campaign', 'utm_source', 'source', 'channel'] as $key) {
            if (filled($attribution[$key] ?? null)) {
                $label = trim((string) $attribution[$key]);
                break;
            }
        }

        return [
            'attribution' => $attribution,
            'attribution_label' => $label,
            'metadata' => array_filter([
                'payment_status' => $payment->status,
                'payment_method' => $payment->payment_method,
                'provider' => $payment->provider,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function buildLegacyRowsFromAttendees(Event $event): Collection
    {
        $attendees = $event->relationLoaded('attendees')
            ? $event->attendees
            : $event->attendees()->get();

        return $attendees
            ->whereIn('status', [EventAttendee::STATUS_CONFIRMED, EventAttendee::STATUS_ATTENDED])
            ->groupBy(function (EventAttendee $attendee) {
                return data_get($attendee->attendee_metadata, 'order_id') ?: 'legacy-attendee-'.$attendee->id;
            })
            ->map(function (Collection $group, string $orderId) {
                $first = $group->first();
                $feeBreakdown = is_array(data_get($first, 'attendee_metadata.fee_breakdown')) ? data_get($first, 'attendee_metadata.fee_breakdown') : [];

                return [
                    'order_id' => $orderId,
                    'payment_reference' => $first->payment_reference,
                    'payout_status' => EventPayoutLedgerEntry::STATUS_READY,
                    'ticket_quantity' => (int) $group->count(),
                    'gross_revenue' => (float) ($feeBreakdown['base_amount'] ?? $group->sum('price_paid_ugx')),
                    'customer_paid_total' => (float) ($feeBreakdown['total_amount'] ?? $group->sum('price_paid_ugx')),
                    'tesotunes_fee_revenue' => (float) ($feeBreakdown['total_fee_amount'] ?? 0),
                    'platform_commission_amount' => (float) ($feeBreakdown['platform_commission_amount'] ?? 0),
                    'processing_fee_amount' => (float) ($feeBreakdown['processing_fee_amount'] ?? 0),
                    'organizer_net_amount' => (float) ($feeBreakdown['organizer_net_amount'] ?? $group->sum('price_paid_ugx')),
                    'fee_source' => $feeBreakdown['fee_source'] ?? 'legacy_attendee_metadata',
                    'attribution_label' => data_get($first, 'attendee_metadata.attribution.campaign_code')
                        ?? data_get($first, 'attendee_metadata.attribution.utm_campaign')
                        ?? data_get($first, 'attendee_metadata.attribution.source'),
                    'occurred_at' => $first->created_at?->toIso8601String(),
                    'payout_ready_at' => $first->confirmed_at?->toIso8601String(),
                    'paid_out_at' => null,
                ];
            })
            ->values();
    }
}
