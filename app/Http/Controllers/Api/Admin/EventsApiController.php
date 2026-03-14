<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventsApiController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/events/stats
     */
    public function stats()
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:events:stats', now()->addMinutes(5), function () {
                return [
                    'upcoming_count' => Event::upcoming()->count(),
                    'total_events' => Event::count(),
                    'tickets_sold_30d' => Event::where('created_at', '>=', now()->subDays(30))
                        ->sum('tickets_sold'),
                    'avg_attendance' => (int) Event::avg('attendee_count'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to retrieve event stats.');
    }

    /**
     * GET /api/admin/events
     */
    public function index(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $events = Event::with(['organizer', 'location', 'tickets'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('title', 'like', '%'.$request->search.'%')
                            ->orWhere('description', 'like', '%'.$request->search.'%');
                    });
                })
                ->when($request->filled('status') && $request->status !== 'all', function ($q) use ($request) {
                    if ($request->status === 'upcoming') {
                        $q->upcoming();
                    } elseif ($request->status === 'ongoing') {
                        $q->where('starts_at', '<=', now())->where('ends_at', '>=', now());
                    } elseif ($request->status === 'completed') {
                        $q->where('ends_at', '<', now());
                    } else {
                        $q->where('status', $request->status);
                    }
                })
                ->when($request->filled('month'), function ($q) use ($request) {
                    $parts = explode('-', $request->month);
                    if (count($parts) === 2) {
                        $q->whereYear('starts_at', $parts[0])->whereMonth('starts_at', $parts[1]);
                    }
                })
                ->when($request->filled('artist_id'), function ($q) use ($request) {
                    $q->where('artist_id', (int) $request->artist_id);
                })
                ->when($request->filled('user_id'), function ($q) use ($request) {
                    $q->where('user_id', (int) $request->user_id);
                })
                ->orderByDesc('starts_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => EventResource::collection($events),
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                ],
            ]);
        }, 'Failed to retrieve events.');
    }

    /**
     * GET /api/admin/events/{id}
     */
    public function show(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::with(['organizer', 'location', 'tickets'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
            ]);
        }, 'Failed to retrieve event.');
    }

    /**
     * POST /api/admin/events
     */
    public function store(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:200',
                'slug' => 'nullable|string|max:220',
                'description' => 'nullable|string',
                'short_description' => 'nullable|string|max:500',
                'event_type' => 'nullable|string',
                'venue_name' => 'nullable|string',
                'venue_address' => 'nullable|string',
                'city' => 'nullable|string',
                'country' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'start_date' => 'nullable|date',
                'start_time' => 'nullable|string',
                'end_date' => 'nullable|date',
                'end_time' => 'nullable|string',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date',
                'timezone' => 'nullable|string',
                'is_virtual' => 'nullable|boolean',
                'virtual_link' => 'nullable|url',
                'is_free' => 'nullable|boolean',
                'currency' => 'nullable|string|max:10',
                'attendee_limit' => 'nullable|integer|min:1',
                'is_featured' => 'nullable|boolean',
                'status' => 'nullable|in:draft,published,cancelled,completed,postponed',
                'cover_image' => 'nullable|file|image|max:5120',
                'ticket_tiers' => 'nullable',
                'category' => 'nullable|string',
            ]);

            // Combine date+time if provided separately
            if (! isset($validated['starts_at']) && isset($validated['start_date'])) {
                $validated['starts_at'] = $validated['start_date'].' '.($validated['start_time'] ?? '00:00:00');
            }
            if (! isset($validated['ends_at']) && isset($validated['end_date'])) {
                $validated['ends_at'] = $validated['end_date'].' '.($validated['end_time'] ?? '23:59:59');
            }

            // Handle image upload — store in 'artwork' column via StorageHelper (supports local + DO Spaces)
            if ($request->hasFile('cover_image')) {
                $validated['artwork'] = StorageHelper::store($request->file('cover_image'), 'events/covers');
                unset($validated['cover_image']);
            } else {
                unset($validated['cover_image']);
            }

            // Clean up non-model fields
            unset($validated['start_date'], $validated['start_time'], $validated['end_date'], $validated['end_time'], $validated['ticket_tiers'], $validated['short_description']);

            $validated['uuid'] = Str::uuid();
            $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']).'-'.Str::random(6);
            $validated['organizer_id'] = auth()->id();
            $validated['organizer_type'] = 'user';
            // Only set user_id if authenticated (legacy field)
            if (auth()->check()) {
                $validated['user_id'] = auth()->id();
            }
            $validated['status'] = $validated['status'] ?? 'draft';
            $validated['timezone'] = $validated['timezone'] ?? 'Africa/Nairobi';

            $event = Event::create($validated);

            // Create event location if venue info provided (skip if table doesn't exist)
            if ($request->filled('venue_name') || $request->filled('city')) {
                try {
                    if (\Schema::hasTable('event_locations')) {
                        $location = EventLocation::create([
                            'uuid' => Str::uuid(),
                            'name' => $request->input('venue_name', $validated['title'].' Venue'),
                            'address' => $request->input('venue_address', ''),
                            'city' => $request->input('city', ''),
                            'country' => $request->input('country', 'Uganda'),
                            'capacity' => $validated['attendee_limit'] ?? null,
                        ]);
                        $event->update(['event_location_id' => $location->id]);
                    }
                } catch (\Exception $e) {
                    // Skip if event_locations table doesn't exist
                }
            }

            // Create ticket tiers if provided (skip if table doesn't exist)
            $ticketTiers = $request->input('ticket_tiers');
            if ($ticketTiers && \Schema::hasTable('event_tickets')) {
                if (is_string($ticketTiers)) {
                    $ticketTiers = json_decode($ticketTiers, true);
                }
                if (is_array($ticketTiers)) {
                    foreach ($ticketTiers as $i => $tier) {
                        EventTicket::create([
                            'uuid' => Str::uuid(),
                            'event_id' => $event->id,
                            'name' => $tier['name'] ?? 'General',
                            'description' => $tier['description'] ?? '',
                            'price_ugx' => $tier['price'] ?? $tier['price_ugx'] ?? 0,
                            'price_credits' => $tier['price_credits'] ?? 0,
                            'is_free' => ($tier['price'] ?? $tier['price_ugx'] ?? 0) == 0,
                            'quantity_total' => $tier['quantity'] ?? $tier['quantity_total'] ?? 100,
                            'quantity_sold' => 0,
                            'min_per_order' => $tier['min_per_order'] ?? 1,
                            'max_per_order' => $tier['max_per_order'] ?? 10,
                            'sale_starts_at' => $tier['sales_start_date'] ?? $tier['sale_starts_at'] ?? now(),
                            'sale_ends_at' => $tier['sales_end_date'] ?? $tier['sale_ends_at'] ?? $event->starts_at,
                            'is_active' => true,
                            'sort_order' => $i,
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully.',
                'data' => new EventResource($event->load(['organizer'])),
            ], 201);
        }, 'Failed to create event.');
    }

    /**
     * PUT /api/admin/events/{id}
     */
    public function update(Request $request, int $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $event = Event::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:200',
                'slug' => 'nullable|string|max:220',
                'description' => 'nullable|string',
                'short_description' => 'nullable|string|max:500',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date',
                'start_date' => 'nullable|date',
                'start_time' => 'nullable|string',
                'end_date' => 'nullable|date',
                'end_time' => 'nullable|string',
                'timezone' => 'nullable|string',
                'is_virtual' => 'nullable|boolean',
                'virtual_link' => 'nullable|url',
                'attendee_limit' => 'nullable|integer|min:1',
                'status' => 'nullable|in:draft,published,cancelled,completed,postponed',
                'cover_image' => 'nullable|file|image|max:5120',
                'ticket_tiers' => 'nullable',
                'category' => 'nullable|string',
                'venue_name' => 'nullable|string',
                'venue_address' => 'nullable|string',
                'city' => 'nullable|string',
                'country' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'is_free' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'event_type' => 'nullable|string',
                'currency' => 'nullable|string|max:10',
            ]);

            // Combine date+time
            if (! isset($validated['starts_at']) && isset($validated['start_date'])) {
                $validated['starts_at'] = $validated['start_date'].' '.($validated['start_time'] ?? '00:00:00');
            }
            if (! isset($validated['ends_at']) && isset($validated['end_date'])) {
                $validated['ends_at'] = $validated['end_date'].' '.($validated['end_time'] ?? '23:59:59');
            }

            // Handle image upload — store in 'artwork' column via StorageHelper (supports local + DO Spaces)
            if ($request->hasFile('cover_image')) {
                // Delete old artwork if it exists
                if ($event->artwork) {
                    StorageHelper::delete($event->artwork);
                }
                $validated['artwork'] = StorageHelper::store($request->file('cover_image'), 'events/covers');
                unset($validated['cover_image']);
            } else {
                unset($validated['cover_image']);
            }

            unset($validated['start_date'], $validated['start_time'], $validated['end_date'], $validated['end_time'], $validated['ticket_tiers']);

            if (isset($validated['title']) && ! isset($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['title']).'-'.Str::random(6);
            }

            $event->update($validated);

            // Update ticket tiers if provided
            $ticketTiers = $request->input('ticket_tiers');
            if ($ticketTiers) {
                if (is_string($ticketTiers)) {
                    $ticketTiers = json_decode($ticketTiers, true);
                }
                if (is_array($ticketTiers)) {
                    // Remove existing tiers that haven't sold any tickets
                    $event->tickets()->where('quantity_sold', 0)->delete();

                    foreach ($ticketTiers as $i => $tier) {
                        if (isset($tier['id'])) {
                            // Update existing tier
                            EventTicket::where('id', $tier['id'])->where('event_id', $event->id)->update([
                                'name' => $tier['name'] ?? 'General',
                                'description' => $tier['description'] ?? '',
                                'price_ugx' => $tier['price'] ?? $tier['price_ugx'] ?? 0,
                                'price_credits' => $tier['price_credits'] ?? 0,
                                'quantity_total' => $tier['quantity'] ?? $tier['quantity_total'] ?? 100,
                                'max_per_order' => $tier['max_per_order'] ?? 10,
                                'sort_order' => $i,
                            ]);
                        } else {
                            // Create new tier
                            EventTicket::create([
                                'uuid' => Str::uuid(),
                                'event_id' => $event->id,
                                'name' => $tier['name'] ?? 'General',
                                'description' => $tier['description'] ?? '',
                                'price_ugx' => $tier['price'] ?? $tier['price_ugx'] ?? 0,
                                'price_credits' => $tier['price_credits'] ?? 0,
                                'is_free' => ($tier['price'] ?? $tier['price_ugx'] ?? 0) == 0,
                                'quantity_total' => $tier['quantity'] ?? $tier['quantity_total'] ?? 100,
                                'quantity_sold' => 0,
                                'min_per_order' => $tier['min_per_order'] ?? 1,
                                'max_per_order' => $tier['max_per_order'] ?? 10,
                                'sale_starts_at' => $tier['sales_start_date'] ?? $tier['sale_starts_at'] ?? now(),
                                'sale_ends_at' => $tier['sales_end_date'] ?? $tier['sale_ends_at'] ?? $event->starts_at,
                                'is_active' => true,
                                'sort_order' => $i,
                            ]);
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Event updated successfully.',
                'data' => new EventResource($event->fresh()->load(['organizer', 'location', 'tickets'])),
            ]);
        }, 'Failed to update event.');
    }

    /**
     * DELETE /api/admin/events/{id}
     */
    public function destroy(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            Event::findOrFail($id)->delete();

            return response()->json(['success' => true, 'message' => 'Event deleted successfully']);
        }, 'Failed to delete event.');
    }

    /**
     * POST /api/admin/events/{id}/publish
     */
    public function publish(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::findOrFail($id);

            if ($event->status !== 'published') {
                $event->publish();
            }

            return response()->json([
                'success' => true,
                'message' => 'Event published successfully.',
                'data' => new EventResource($event->fresh()->load(['organizer', 'location', 'tickets'])),
            ]);
        }, 'Failed to publish event.');
    }

    /**
     * POST /api/admin/events/{id}/toggle-featured
     */
    public function toggleFeatured(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::findOrFail($id);

            $event->update([
                'is_featured' => ! $event->is_featured,
            ]);

            return response()->json([
                'success' => true,
                'message' => $event->is_featured ? 'Event featured successfully.' : 'Event unfeatured successfully.',
                'data' => new EventResource($event->fresh()->load(['organizer', 'location', 'tickets'])),
            ]);
        }, 'Failed to update event featured state.');
    }

    /**
     * GET /api/admin/events/{id}/analytics
     */
    public function analytics(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::with(['tickets', 'attendees.ticket', 'interestedUsers'])
                ->findOrFail($id);

            $confirmedAttendees = $event->attendees->whereIn('status', ['confirmed', 'attended']);
            $ticketsSold = (int) $event->tickets->sum('quantity_sold');
            $interestedCount = (int) $event->interestedUsers->count();
            $revenue = (float) $confirmedAttendees->sum(fn ($attendee) => $attendee->price_paid_ugx ?? $attendee->amount_paid ?? 0);
            $revenueCredits = (float) $confirmedAttendees->sum('price_paid_credits');
            $checkIns = (int) $event->attendees->whereNotNull('checked_in_at')->count();

            $byTier = $event->tickets->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'name' => $ticket->name,
                    'sold' => (int) $ticket->quantity_sold,
                    'total' => $ticket->quantity_total,
                    'revenue' => (float) ($ticket->quantity_sold * ($ticket->price_ugx ?? 0)),
                    'available' => (int) ($ticket->quantity_available ?? 0),
                ];
            })->values();

            $byDate = $event->attendees()
                ->where(function ($query) {
                    $query->whereIn('status', ['confirmed', 'attended'])
                        ->orWhereNotNull('checked_in_at');
                })
                ->selectRaw('DATE(created_at) as date, COUNT(*) as tickets_sold, SUM(COALESCE(price_paid_ugx, amount_paid, 0)) as revenue')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy(DB::raw('DATE(created_at)'))
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'tickets_sold' => (int) $row->tickets_sold,
                    'revenue' => (float) $row->revenue,
                ])
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'event_id' => $event->id,
                    'status' => $event->status,
                    'tickets_sold' => $ticketsSold,
                    'total_attendees' => (int) $confirmedAttendees->count(),
                    'interested_count' => $interestedCount,
                    'check_ins' => $checkIns,
                    'revenue' => $revenue,
                    'revenue_credits' => $revenueCredits,
                    'conversion_rate' => $interestedCount > 0 ? round(($ticketsSold / $interestedCount) * 100, 2) : 0.0,
                    'sell_through_rate' => $event->tickets->sum('quantity_total') > 0
                        ? round(($ticketsSold / max(1, $event->tickets->sum('quantity_total'))) * 100, 2)
                        : 0.0,
                    'by_tier' => $byTier,
                    'by_date' => $byDate,
                ],
            ]);
        }, 'Failed to retrieve event analytics.');
    }

    /**
     * GET /api/admin/events/{id}/attendees
     */
    public function attendees(int $id, Request $request)
    {
        return $this->handleApiAction(function () use ($id, $request) {
            $perPage = min((int) $request->get('per_page', 20), 100);
            $event = Event::findOrFail($id);

            $attendees = $event->attendees()
                ->with(['user:id,name,username,email,avatar', 'ticket:id,event_id,name,price_ugx'])
                ->when($request->filled('status'), function ($query) use ($request) {
                    $query->where('status', $request->status);
                })
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => collect($attendees->items())->map(function ($attendee) {
                    return [
                        'id' => $attendee->id,
                        'ticket_number' => $attendee->confirmation_code,
                        'status' => $attendee->status,
                        'payment_status' => $attendee->payment_status,
                        'quantity' => (int) ($attendee->quantity ?? 1),
                        'amount_paid' => (float) ($attendee->amount_paid ?? $attendee->price_paid_ugx ?? 0),
                        'checked_in_at' => $attendee->checked_in_at?->toIso8601String(),
                        'confirmed_at' => $attendee->confirmed_at?->toIso8601String(),
                        'created_at' => $attendee->created_at?->toIso8601String(),
                        'attendee' => [
                            'name' => $attendee->attendee_name,
                            'email' => $attendee->attendee_email,
                            'phone' => $attendee->attendee_phone,
                        ],
                        'user' => $attendee->user ? [
                            'id' => $attendee->user->id,
                            'name' => $attendee->user->name,
                            'username' => $attendee->user->username,
                            'email' => $attendee->user->email,
                            'avatar' => $attendee->user->avatar,
                        ] : null,
                        'ticket' => $attendee->ticket ? [
                            'id' => $attendee->ticket->id,
                            'name' => $attendee->ticket->name,
                            'price_ugx' => (float) ($attendee->ticket->price_ugx ?? 0),
                        ] : null,
                    ];
                })->values(),
                'meta' => [
                    'current_page' => $attendees->currentPage(),
                    'last_page' => $attendees->lastPage(),
                    'per_page' => $attendees->perPage(),
                    'total' => $attendees->total(),
                ],
                'links' => [
                    'first' => $attendees->url(1),
                    'last' => $attendees->url($attendees->lastPage()),
                    'prev' => $attendees->previousPageUrl(),
                    'next' => $attendees->nextPageUrl(),
                ],
            ]);
        }, 'Failed to retrieve event attendees.');
    }

    /**
     * GET /api/admin/events/{id}/registrations
     */
    public function registrations(int $id, Request $request)
    {
        return $this->attendees($id, $request);
    }
}
