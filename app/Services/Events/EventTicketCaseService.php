<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicketCase;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EventTicketCaseService
{
    public function __construct(
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
    ) {}

    public function openCase(User $user, EventAttendee $attendee, array $validated): EventTicketCase
    {
        $paymentId = data_get($attendee->attendee_metadata, 'payment_id');

        return EventTicketCase::firstOrCreate(
            [
                'event_attendee_id' => $attendee->id,
                'case_type' => $validated['case_type'],
                'status' => EventTicketCase::STATUS_OPEN,
            ],
            [
                'event_id' => $attendee->event_id,
                'payment_id' => $paymentId ?: null,
                'requested_by_user_id' => $user->id,
                'dispute_category' => $validated['dispute_category'] ?? null,
                'escalation_status' => $validated['case_type'] === EventTicketCase::TYPE_PAYMENT_DISPUTE
                    ? EventTicketCase::ESCALATION_REVIEW
                    : EventTicketCase::ESCALATION_NONE,
                'reason' => $validated['reason'],
                'gateway_reference' => $validated['gateway_reference'] ?? null,
                'evidence_url' => $validated['evidence_url'] ?? null,
                'evidence_notes' => $validated['evidence_notes'] ?? null,
                'requested_refund_amount' => $validated['requested_refund_amount'] ?? null,
            ],
        );
    }

    public function resolveCase(User $user, Event $event, EventTicketCase $case, array $validated): EventTicketCase
    {
        if ($case->event_id !== $event->id) {
            abort(404, 'Ticket support case not found for this event.');
        }

        if ($case->status !== EventTicketCase::STATUS_OPEN) {
            abort(422, 'This ticket support case has already been resolved.');
        }

        return DB::transaction(function () use ($user, $case, $validated) {
            $case->loadMissing(['attendee.ticket', 'payment']);
            $attendee = $case->attendee;

            if (! $attendee) {
                abort(404, 'Ticket attendee no longer exists.');
            }

            $decision = $validated['decision'];
            $resolutionNotes = trim((string) ($validated['resolution_notes'] ?? ''));
            $approvedRefundAmount = $validated['approved_refund_amount'] ?? $case->requested_refund_amount;

            if ($decision === 'approve') {
                if ($attendee->hasAttended()) {
                    abort(422, 'Checked-in tickets cannot be approved for refund/dispute reversal from this workflow.');
                }

                if (! $attendee->isCancelled()) {
                    $ticket = $attendee->ticket()->lockForUpdate()->first();
                    if ($ticket) {
                        $ticket->reverseSale((int) ($attendee->quantity ?? 1));
                    }

                    $metadata = $attendee->attendee_metadata ?? [];
                    $supportHistory = array_values(array_filter([
                        ...($metadata['support_cases'] ?? []),
                        [
                            'case_id' => $case->id,
                            'case_type' => $case->case_type,
                            'decision' => 'approved',
                            'resolved_at' => now()->toIso8601String(),
                            'resolved_by_user_id' => $user->id,
                            'approved_refund_amount' => $approvedRefundAmount !== null ? (float) $approvedRefundAmount : null,
                            'resolution_notes' => $resolutionNotes !== '' ? $resolutionNotes : null,
                        ],
                    ]));

                    $attendee->forceFill([
                        'payment_status' => $case->case_type === EventTicketCase::TYPE_REFUND_REQUEST ? 'refund_review_approved' : 'dispute_review_approved',
                        'attendee_metadata' => [
                            ...$metadata,
                            'support_cases' => $supportHistory,
                            'latest_support_case_id' => $case->id,
                        ],
                    ])->save();

                    $attendee->cancel();
                }

                if ($case->payment instanceof Payment) {
                    $paymentData = $case->payment->payment_data ?? [];
                    $paymentData['event_ticket_cases'] = array_values(array_filter([
                        ...($paymentData['event_ticket_cases'] ?? []),
                        [
                            'case_id' => $case->id,
                            'case_type' => $case->case_type,
                            'decision' => 'approved',
                            'approved_refund_amount' => $approvedRefundAmount !== null ? (float) $approvedRefundAmount : null,
                            'resolved_at' => now()->toIso8601String(),
                        ],
                    ]));

                    $case->payment->forceFill([
                        'payment_data' => $paymentData,
                        'refund_reason' => $case->case_type === EventTicketCase::TYPE_REFUND_REQUEST ? $case->reason : $case->payment->refund_reason,
                    ])->save();
                }

                $this->eventPayoutLedgerService->recordSupportCaseAdjustment($case, (float) ($approvedRefundAmount ?? 0));
            }

            $case->forceFill([
                'status' => $decision === 'approve' ? EventTicketCase::STATUS_APPROVED : EventTicketCase::STATUS_REJECTED,
                'escalation_status' => $case->case_type === EventTicketCase::TYPE_PAYMENT_DISPUTE
                    ? EventTicketCase::ESCALATION_RESOLVED
                    : $case->escalation_status,
                'resolved_by_user_id' => $user->id,
                'resolution_notes' => $resolutionNotes !== '' ? $resolutionNotes : null,
                'approved_refund_amount' => $decision === 'approve' && $approvedRefundAmount !== null ? $approvedRefundAmount : null,
                'resolved_at' => now(),
            ])->save();

            return $case->fresh(['attendee.ticket', 'requestedBy', 'resolvedBy']);
        });
    }
}
