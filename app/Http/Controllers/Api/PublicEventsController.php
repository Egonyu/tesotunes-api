<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventWaitlistEntry;
use Illuminate\Http\Request;

class PublicEventsController extends Controller
{
    /**
     * GET /api/events — public events listing
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 12), 100);

        $events = Event::with(['organizer', 'location'])
            ->where('status', 'published')
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = escape_like($request->search);
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('category'), function ($q) use ($request) {
                $q->where('category', $request->category);
            })
            ->when($request->filled('upcoming') && $request->upcoming === 'true', function ($q) {
                $q->where('starts_at', '>=', now());
            })
            ->orderByDesc('starts_at')
            ->paginate($perPage);

        return EventResource::collection($events);
    }

    /**
     * GET /api/events/featured
     */
    public function featured()
    {
        $events = Event::with(['organizer', 'location'])
            ->where('status', 'published')
            ->where('is_featured', true)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(6)
            ->get();

        return EventResource::collection($events);
    }

    /**
     * GET /api/events/upcoming
     */
    public function upcoming(Request $request)
    {
        $limit = min((int) $request->get('limit', 10), 50);

        $events = Event::with(['organizer', 'location'])
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        return EventResource::collection($events);
    }

    /**
     * GET /api/events/categories
     */
    public function categories()
    {
        $categories = Event::whereNotNull('category')
            ->where('status', 'published')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        return response()->json(['data' => $categories]);
    }

    /**
     * GET /api/events/{id}
     */
    public function show($id)
    {
        $event = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'waitlistEntries'])
            ->where('status', 'published')
            ->findOrFail($id);

        return response()->json(['data' => new EventResource($event)]);
    }

    public function joinWaitlist(Request $request, int $id)
    {
        $user = $request->user();
        $event = Event::with('tickets')->where('status', 'published')->findOrFail($id);

        abort_if($event->ticketing_mode === Event::TICKETING_MODE_EXTERNAL_ONLY, 422, 'Waitlist is not available for organizer-managed external ticketing events.');
        abort_if($event->tickets->isEmpty(), 422, 'Waitlist is only available for ticketed events.');

        $hasAvailableTickets = $event->tickets->contains(fn ($ticket) => (int) ($ticket->quantity_available ?? 0) > 0);
        abort_if($hasAvailableTickets, 422, 'Tickets are still available for this event.');

        $validated = $request->validate([
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:20',
        ]);

        $entry = EventWaitlistEntry::updateOrCreate(
            [
                'event_id' => $event->id,
                'user_id' => $user->id,
            ],
            [
                'email' => $validated['email'] ?? $user->email,
                'phone' => $validated['phone'] ?? $user->phone,
                'status' => EventWaitlistEntry::STATUS_ACTIVE,
                'joined_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Joined the event waitlist successfully.',
            'data' => [
                'event_id' => $event->id,
                'waitlist_count' => $event->waitlistEntries()->where('status', EventWaitlistEntry::STATUS_ACTIVE)->count(),
                'waitlist_joined' => true,
                'entry_id' => $entry->id,
            ],
        ], 201);
    }
}
