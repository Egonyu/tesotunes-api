<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\EventTicketCase;
use App\Notifications\EventTicketConfirmationNotification;
use App\Services\Events\EventDiscountCodeService;
use App\Services\Events\EventFeeCalculatorService;
use App\Services\Events\EventTicketCaseService;
use App\Services\Events\EventTicketingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class TicketController extends Controller
{
    public function __construct(
        private readonly EventTicketingService $eventTicketingService,
        private readonly EventFeeCalculatorService $eventFeeCalculatorService,
        private readonly EventDiscountCodeService $eventDiscountCodeService,
        private readonly EventTicketCaseService $eventTicketCaseService,
    ) {}

    /**
     * POST /api/tickets/purchase
     */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'ticket_tier_id' => 'nullable|integer|exists:event_tickets,id',
            'quantity' => 'nullable|integer|min:1|max:10',
            'tickets' => 'nullable|array|min:1|max:10',
            'tickets.*.ticket_tier_id' => 'required_with:tickets|integer|exists:event_tickets,id',
            'tickets.*.quantity' => 'required_with:tickets|integer|min:1|max:10',
            'payment_method' => 'required|in:wallet,mtn_momo,airtel_money,card,credits',
            'discount_code' => 'nullable|string|max:80',
            'phone' => 'nullable|string',
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
            'attendee_assignments' => 'nullable|array|max:10',
            'attendee_assignments.*.ticket_tier_id' => 'required_with:attendee_assignments|integer|exists:event_tickets,id',
            'attendee_assignments.*.attendees' => 'nullable|array|max:20',
            'attendee_assignments.*.attendees.*.name' => 'nullable|string|max:150',
            'attendee_assignments.*.attendees.*.email' => 'nullable|email|max:150',
            'attendee_assignments.*.attendees.*.phone' => 'nullable|string|max:20',
            'attendee_assignments.*.attendees.*.save_profile' => 'nullable|boolean',
            'attribution' => 'nullable|array',
            'attribution.source' => 'nullable|string|max:80',
            'attribution.channel' => 'nullable|string|max:80',
            'attribution.campaign_code' => 'nullable|string|max:120',
            'attribution.referral_code' => 'nullable|string|max:120',
            'attribution.promoter_code' => 'nullable|string|max:120',
            'attribution.utm_source' => 'nullable|string|max:120',
            'attribution.utm_medium' => 'nullable|string|max:120',
            'attribution.utm_campaign' => 'nullable|string|max:150',
            'attribution.utm_term' => 'nullable|string|max:150',
            'attribution.utm_content' => 'nullable|string|max:150',
            'attribution.landing_page' => 'nullable|string|max:500',
        ]);

        $validated['tickets'] = $this->normalizeTicketSelections($validated);
        $validated['attendee_assignments'] = $this->normalizeAttendeeAssignments($validated['attendee_assignments'] ?? null);
        $result = $this->eventTicketingService->purchase(auth()->user(), $validated);

        return response()->json($result, 201);
    }

    /**
     * POST /api/tickets/quote
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'ticket_tier_id' => 'nullable|integer|exists:event_tickets,id',
            'quantity' => 'nullable|integer|min:1|max:10',
            'tickets' => 'nullable|array|min:1|max:10',
            'tickets.*.ticket_tier_id' => 'required_with:tickets|integer|exists:event_tickets,id',
            'tickets.*.quantity' => 'required_with:tickets|integer|min:1|max:10',
            'discount_code' => 'nullable|string|max:80',
        ]);

        $selections = $this->normalizeTicketSelections($validated);
        $tickets = EventTicket::with('event.organizer.artist', 'event.user.artist')
            ->where('event_id', $validated['event_id'])
            ->whereIn('id', collect($selections)->pluck('ticket_tier_id')->all())
            ->get()
            ->keyBy('id');

        abort_if($tickets->count() !== count($selections), 404, 'One or more ticket tiers were not found for this event.');

        $event = $tickets->first()?->event;
        $discountCode = null;
        if (! empty($validated['discount_code']) && $event) {
            $discountCode = $this->eventDiscountCodeService
                ->validateForQuote($event, $tickets->values(), $selections, $validated['discount_code'])['discount_code'];
        }

        $quote = $this->eventFeeCalculatorService->calculateForSelections(
            $tickets,
            $selections,
            $discountCode,
        );

        return response()->json([
            'data' => $quote + [
                'event_id' => (int) $validated['event_id'],
            ],
        ]);
    }

    public function validateDiscountCode(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'ticket_tier_id' => 'nullable|integer|exists:event_tickets,id',
            'quantity' => 'nullable|integer|min:1|max:10',
            'tickets' => 'nullable|array|min:1|max:10',
            'tickets.*.ticket_tier_id' => 'required_with:tickets|integer|exists:event_tickets,id',
            'tickets.*.quantity' => 'required_with:tickets|integer|min:1|max:10',
            'code' => 'required|string|max:80',
        ]);

        $selections = $this->normalizeTicketSelections($validated);
        $tickets = EventTicket::with('event.organizer.artist', 'event.user.artist')
            ->where('event_id', $validated['event_id'])
            ->whereIn('id', collect($selections)->pluck('ticket_tier_id')->all())
            ->get()
            ->keyBy('id');

        abort_if($tickets->count() !== count($selections), 404, 'One or more ticket tiers were not found for this event.');

        $event = $tickets->first()?->event;
        $resolved = $this->eventDiscountCodeService->validateForQuote($event, $tickets->values(), $selections, $validated['code']);
        $quote = $this->eventFeeCalculatorService->calculateForSelections(
            $tickets,
            $selections,
            $resolved['discount_code'],
        );

        return response()->json([
            'valid' => true,
            'message' => $resolved['message'],
            'data' => [
                'code' => $resolved['discount_code']->code,
                'discount_amount' => (float) $quote['discount_amount'],
                'quote' => $quote + [
                    'event_id' => (int) $validated['event_id'],
                ],
            ],
        ]);
    }

    private function normalizeTicketSelections(array $validated): array
    {
        if (! empty($validated['tickets']) && is_array($validated['tickets'])) {
            return array_values(array_map(static fn (array $ticket) => [
                'ticket_tier_id' => (int) $ticket['ticket_tier_id'],
                'quantity' => (int) $ticket['quantity'],
            ], $validated['tickets']));
        }

        return [[
            'ticket_tier_id' => (int) $validated['ticket_tier_id'],
            'quantity' => (int) $validated['quantity'],
        ]];
    }

    private function normalizeAttendeeAssignments(mixed $assignments): array
    {
        if (! is_array($assignments)) {
            return [];
        }

        return array_values(array_map(static function (array $assignment) {
            $attendees = array_values(array_filter(array_map(static function ($attendee) {
                if (! is_array($attendee)) {
                    return null;
                }

                $name = trim((string) ($attendee['name'] ?? ''));
                $email = trim((string) ($attendee['email'] ?? ''));
                $phone = trim((string) ($attendee['phone'] ?? ''));

                if ($name === '' && $email === '' && $phone === '') {
                    return null;
                }

                return [
                    'name' => $name !== '' ? $name : null,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'save_profile' => (bool) ($attendee['save_profile'] ?? false),
                ];
            }, $assignment['attendees'] ?? [])));

            return [
                'ticket_tier_id' => (int) $assignment['ticket_tier_id'],
                'attendees' => $attendees,
            ];
        }, $assignments));
    }

    /**
     * GET /api/tickets/my — user's tickets
     */
    public function myTickets(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $registrations = EventAttendee::with(['event.organizer.artist', 'event.location', 'ticket'])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = TicketResource::collection($registrations->getCollection())->resolve();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $registrations->currentPage(),
                'last_page' => $registrations->lastPage(),
                'per_page' => $registrations->perPage(),
                'total' => $registrations->total(),
            ],
        ]);
    }

    public function attendeeProfiles(Request $request)
    {
        $user = auth()->user();
        $limit = min((int) $request->integer('limit', 10), 20);

        $profiles = EventAttendee::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNotNull('attendee_name')
                    ->orWhereNotNull('attendee_email')
                    ->orWhereNotNull('attendee_phone');
            })
            ->orderByDesc('created_at')
            ->get(['attendee_name', 'attendee_email', 'attendee_phone', 'created_at'])
            ->map(function (EventAttendee $attendee) {
                $name = trim((string) ($attendee->attendee_name ?? ''));
                $email = trim((string) ($attendee->attendee_email ?? ''));
                $phone = trim((string) ($attendee->attendee_phone ?? ''));

                return [
                    'name' => $name,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'last_used_at' => $attendee->created_at?->toIso8601String(),
                    'key' => strtolower(implode('|', [$name, $email, $phone])),
                ];
            })
            ->filter(fn (array $profile) => $profile['name'] !== '' || $profile['email'] !== null || $profile['phone'] !== null)
            ->unique('key')
            ->take($limit)
            ->values()
            ->map(fn (array $profile) => [
                'name' => $profile['name'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'last_used_at' => $profile['last_used_at'],
            ]);

        return response()->json([
            'data' => $profiles,
        ]);
    }

    /**
     * GET /api/tickets/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $registration = EventAttendee::with(['event.organizer.artist', 'event.location', 'ticket'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'data' => new TicketResource($registration),
        ]);
    }

    public function cases(int $id)
    {
        $user = auth()->user();
        $registration = EventAttendee::where('user_id', $user->id)->findOrFail($id);

        $cases = EventTicketCase::with(['requestedBy', 'resolvedBy'])
            ->where('event_attendee_id', $registration->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EventTicketCase $case) => $this->serializeTicketCase($case));

        return response()->json([
            'data' => $cases,
        ]);
    }

    public function requestCase(Request $request, int $id)
    {
        $validated = $request->validate([
            'case_type' => 'required|in:refund_request,payment_dispute',
            'dispute_category' => 'nullable|string|max:60',
            'reason' => 'required|string|min:10|max:2000',
            'gateway_reference' => 'nullable|string|max:120',
            'evidence_url' => 'nullable|url|max:2048',
            'evidence_notes' => 'nullable|string|max:2000',
            'requested_refund_amount' => 'nullable|numeric|min:0',
        ]);

        $user = auth()->user();
        $registration = EventAttendee::with(['event', 'ticket'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if ($registration->isCancelled()) {
            return response()->json(['message' => 'Cancelled tickets cannot open a new support case.'], 422);
        }

        $case = $this->eventTicketCaseService->openCase($user, $registration, $validated);

        return response()->json([
            'message' => $case->wasRecentlyCreated
                ? 'Ticket support request submitted successfully.'
                : 'An open support request for this ticket already exists.',
            'data' => $this->serializeTicketCase($case->fresh(['requestedBy', 'resolvedBy'])),
        ], $case->wasRecentlyCreated ? 201 : 200);
    }

    public function resend(int $id)
    {
        $user = auth()->user();
        $registration = EventAttendee::with(['event.organizer.artist', 'event.location', 'ticket'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if ($registration->status === EventAttendee::STATUS_CANCELLED) {
            return response()->json(['message' => 'Cancelled tickets cannot be resent'], 422);
        }

        $recipientEmail = $registration->attendee_email ?: $user->email;
        if (blank($recipientEmail)) {
            return response()->json(['message' => 'This ticket does not have a delivery email yet'], 422);
        }

        Notification::route('mail', $recipientEmail)
            ->notify(new EventTicketConfirmationNotification($registration, $registration->ticket, $registration->event));

        $metadata = $registration->attendee_metadata ?? [];
        $resendCount = (int) data_get($metadata, 'wallet_actions.resend_count', 0) + 1;
        data_set($metadata, 'wallet_actions.resend_count', $resendCount);
        data_set($metadata, 'wallet_actions.last_resent_at', now()->toIso8601String());
        data_set($metadata, 'wallet_actions.last_resent_to', $recipientEmail);

        $registration->update([
            'attendee_metadata' => $metadata,
        ]);

        return response()->json([
            'message' => 'Ticket confirmation resent successfully',
            'data' => new TicketResource($registration->fresh(['event.organizer.artist', 'event.location', 'ticket'])),
        ]);
    }

    public function transfer(Request $request, int $id)
    {
        $validated = $request->validate([
            'holder_name' => 'required|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
            'message' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $registration = EventAttendee::with(['event.organizer.artist', 'event.location', 'ticket'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if ($registration->status === EventAttendee::STATUS_CANCELLED || $registration->hasAttended()) {
            return response()->json(['message' => 'This ticket can no longer be transferred'], 422);
        }

        $metadata = $registration->attendee_metadata ?? [];
        $history = collect(data_get($metadata, 'wallet_actions.transfer_history', []))
            ->filter(fn ($entry) => is_array($entry))
            ->values()
            ->all();

        $history[] = [
            'transferred_at' => now()->toIso8601String(),
            'from' => [
                'name' => $registration->attendee_name,
                'email' => $registration->attendee_email,
                'phone' => $registration->attendee_phone,
            ],
            'to' => [
                'name' => $validated['holder_name'],
                'email' => $validated['holder_email'] ?? null,
                'phone' => $validated['holder_phone'] ?? null,
            ],
            'message' => $validated['message'] ?? null,
        ];

        data_set($metadata, 'wallet_actions.transfer_history', $history);
        data_set($metadata, 'wallet_actions.last_transferred_at', now()->toIso8601String());

        $registration->update([
            'attendee_name' => $validated['holder_name'],
            'attendee_email' => $validated['holder_email'] ?? null,
            'attendee_phone' => $validated['holder_phone'] ?? null,
            'attendee_metadata' => $metadata,
        ]);

        if (! empty($validated['holder_email'])) {
            Notification::route('mail', $validated['holder_email'])
                ->notify(new EventTicketConfirmationNotification($registration->fresh(['event', 'ticket']), $registration->ticket, $registration->event));
        }

        return response()->json([
            'message' => 'Ticket holder updated successfully',
            'data' => new TicketResource($registration->fresh(['event.organizer.artist', 'event.location', 'ticket'])),
        ]);
    }

    /**
     * GET /api/tickets/validate/{ticketNumber}
     */
    public function validateTicket(string $ticketNumber)
    {
        $registration = EventAttendee::with(['event'])
            ->where('confirmation_code', $ticketNumber)
            ->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid ticket number', 'valid' => false], 404);
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'id' => $registration->id,
                'ticket_number' => $registration->confirmation_code,
                'status' => $registration->status,
                'holder_name' => $registration->attendee_name,
                'checked_in_at' => $registration->checked_in_at,
                'ticket_source' => data_get($registration->attendee_metadata, 'ticket_source'),
                'printed_ticket_import' => (bool) data_get($registration->attendee_metadata, 'printed_ticket_import', false),
                'validation_notes' => data_get($registration->attendee_metadata, 'validation_notes'),
                'event' => [
                    'id' => $registration->event->id,
                    'title' => $registration->event->title,
                    'starts_at' => $registration->event->starts_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/tickets/check-in
     */
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'ticket_number' => 'required|string',
        ]);

        $registration = EventAttendee::where('confirmation_code', $validated['ticket_number'])->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid ticket number'], 404);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'This ticket has been cancelled'], 422);
        }

        if ($registration->checked_in_at) {
            return response()->json([
                'message' => 'Ticket already checked in at '.$registration->checked_in_at->format('M j, Y g:i A'),
                'data' => [
                    'ticket_number' => $registration->confirmation_code,
                    'checked_in_at' => $registration->checked_in_at->toIso8601String(),
                    'holder_name' => $registration->attendee_name,
                ],
            ], 422);
        }

        $registration->update([
            'checked_in_at' => now(),
            'attended_at' => now(),
            'status' => EventAttendee::STATUS_ATTENDED,
        ]);

        // Award loyalty points if event has a loyalty card
        $event = $registration->event;
        if ($event && ($event->loyalty_points_per_checkin ?? 0) > 0 && $registration->user_id) {
            // Award points via loyalty service
            try {
                $pointsService = app(\App\Services\Loyalty\LoyaltyPointsService::class);
                $pointsService->awardPoints(
                    user: \App\Models\User::find($registration->user_id),
                    basePoints: $event->loyalty_points_per_checkin,
                    source: 'event_checkin',
                    sourceId: $event->id,
                    sourceType: get_class($event),
                    description: "Check-in at: {$event->title}",
                );
            } catch (\Exception $e) {
                // Log but don't fail the check-in
                \Log::warning("Failed to award loyalty points for event check-in: {$e->getMessage()}");
            }
        }

        return response()->json([
            'message' => 'Ticket checked in successfully',
            'data' => [
                'ticket_number' => $registration->confirmation_code,
                'holder_name' => $registration->attendee_name,
                'checked_in_at' => $registration->checked_in_at->toIso8601String(),
                'event' => $event ? $event->title : null,
                'ticket_source' => data_get($registration->attendee_metadata, 'ticket_source'),
                'printed_ticket_import' => (bool) data_get($registration->attendee_metadata, 'printed_ticket_import', false),
                'validation_notes' => data_get($registration->attendee_metadata, 'validation_notes'),
                'loyalty_points_earned' => ($event && ($event->loyalty_points_per_checkin ?? 0) > 0) ? $event->loyalty_points_per_checkin : 0,
            ],
        ]);
    }

    private function serializeTicketCase(EventTicketCase $case): array
    {
        return [
            'id' => $case->id,
            'case_type' => $case->case_type,
            'dispute_category' => $case->dispute_category,
            'status' => $case->status,
            'escalation_status' => $case->escalation_status,
            'reason' => $case->reason,
            'gateway_reference' => $case->gateway_reference,
            'evidence_url' => $case->evidence_url,
            'evidence_notes' => $case->evidence_notes,
            'resolution_notes' => $case->resolution_notes,
            'requested_refund_amount' => $case->requested_refund_amount !== null ? (float) $case->requested_refund_amount : null,
            'approved_refund_amount' => $case->approved_refund_amount !== null ? (float) $case->approved_refund_amount : null,
            'requested_by' => $case->relationLoaded('requestedBy') && $case->requestedBy ? [
                'id' => $case->requestedBy->id,
                'name' => $case->requestedBy->display_name ?? $case->requestedBy->name ?? $case->requestedBy->username,
                'email' => $case->requestedBy->email,
            ] : null,
            'resolved_by' => $case->relationLoaded('resolvedBy') && $case->resolvedBy ? [
                'id' => $case->resolvedBy->id,
                'name' => $case->resolvedBy->display_name ?? $case->resolvedBy->name ?? $case->resolvedBy->username,
                'email' => $case->resolvedBy->email,
            ] : null,
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
        ];
    }
}
