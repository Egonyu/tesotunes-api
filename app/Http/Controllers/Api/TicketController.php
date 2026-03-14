<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventAttendee;
use App\Services\Events\EventTicketingService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private readonly EventTicketingService $eventTicketingService,
    ) {}

    /**
     * POST /api/tickets/purchase
     */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'ticket_tier_id' => 'required|integer|exists:event_tickets,id',
            'quantity' => 'required|integer|min:1|max:10',
            'payment_method' => 'required|in:wallet,mtn_momo,airtel_money,card,credits',
            'phone' => 'nullable|string',
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
        ]);

        $result = $this->eventTicketingService->purchase(auth()->user(), $validated);

        return response()->json($result, 201);
    }

    /**
     * GET /api/tickets/my — user's tickets
     */
    public function myTickets(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $registrations = EventAttendee::with(['event.organizer', 'event.location'])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $registrations->getCollection()->map(function ($r) {
            return [
                'id' => $r->id,
                'ticket_number' => $r->confirmation_code,
                'qr_code' => $r->qr_code,
                'status' => $r->status,
                'holder_name' => $r->attendee_name,
                'holder_email' => $r->attendee_email,
                'price_paid' => (float) $r->price_paid_ugx,
                'price_paid_credits' => (float) $r->price_paid_credits,
                'payment_method' => $r->payment_method,
                'checked_in_at' => $r->checked_in_at,
                'event' => $r->event ? [
                    'id' => $r->event->id,
                    'title' => $r->event->title,
                    'starts_at' => $r->event->starts_at?->toIso8601String(),
                    'artwork' => $r->event->artwork ? url('storage/'.$r->event->artwork) : null,
                    'venue_name' => $r->event->venue_name,
                    'city' => $r->event->city,
                ] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        });

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

    /**
     * GET /api/tickets/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $registration = EventAttendee::with(['event.organizer', 'event.location'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'ticket_number' => $registration->confirmation_code,
                'qr_code' => $registration->qr_code,
                'status' => $registration->status,
                'holder_name' => $registration->attendee_name,
                'holder_email' => $registration->attendee_email,
                'holder_phone' => $registration->attendee_phone,
                'price_paid' => (float) $registration->price_paid_ugx,
                'price_paid_credits' => (float) $registration->price_paid_credits,
                'payment_method' => $registration->payment_method,
                'checked_in_at' => $registration->checked_in_at,
                'confirmed_at' => $registration->confirmed_at,
                'event' => $registration->event ? [
                    'id' => $registration->event->id,
                    'title' => $registration->event->title,
                    'starts_at' => $registration->event->starts_at?->toIso8601String(),
                    'ends_at' => $registration->event->ends_at?->toIso8601String(),
                    'venue_name' => $registration->event->venue_name,
                    'city' => $registration->event->city,
                    'artwork' => $registration->event->artwork ? url('storage/'.$registration->event->artwork) : null,
                ] : null,
                'created_at' => $registration->created_at?->toIso8601String(),
            ],
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
                'loyalty_points_earned' => ($event && ($event->loyalty_points_per_checkin ?? 0) > 0) ? $event->loyalty_points_per_checkin : 0,
            ],
        ]);
    }
}
