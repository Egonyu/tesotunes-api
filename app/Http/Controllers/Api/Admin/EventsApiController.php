<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Traits\HandlesApiErrors;

class EventsApiController extends Controller
{
    use HandlesApiErrors;
    /**
     * GET /api/admin/events/stats
     */
    public function stats()
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'upcoming_count' => Event::upcoming()->count(),
                    'total_events' => Event::count(),
                    'tickets_sold_30d' => Event::where('created_at', '>=', now()->subDays(30))
                        ->sum('tickets_sold'),
                    'avg_attendance' => (int) Event::avg('attendee_count'),
                ],
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

            // Handle image upload
            if ($request->hasFile('cover_image')) {
                $file = $request->file('cover_image');
                $filename = time().'_'.$file->getClientOriginalName();
                $file->move(public_path('uploads/events'), $filename);
                $validated['cover_image'] = 'uploads/events/'.$filename;
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
                'is_free' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'event_type' => 'nullable|string',
            ]);

            // Combine date+time
            if (! isset($validated['starts_at']) && isset($validated['start_date'])) {
                $validated['starts_at'] = $validated['start_date'].' '.($validated['start_time'] ?? '00:00:00');
            }
            if (! isset($validated['ends_at']) && isset($validated['end_date'])) {
                $validated['ends_at'] = $validated['end_date'].' '.($validated['end_time'] ?? '23:59:59');
            }

            // Handle image upload
            if ($request->hasFile('cover_image')) {
                $file = $request->file('cover_image');
                $filename = time().'_'.$file->getClientOriginalName();
                $file->move(public_path('uploads/events'), $filename);
                $validated['cover_image'] = 'uploads/events/'.$filename;
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
     * GET /api/admin/events/{id}/registrations
     */
    public function registrations(int $id, Request $request)
    {
        return $this->handleApiAction(function () use ($id, $request) {
            $perPage = min((int) $request->get('per_page', 20), 100);
            $event = Event::findOrFail($id);

            $registrations = $event->attendees()
                ->with('user:id,name,username,email,avatar')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $registrations->items(),
                'meta' => [
                    'current_page' => $registrations->currentPage(),
                    'last_page' => $registrations->lastPage(),
                    'per_page' => $registrations->perPage(),
                    'total' => $registrations->total(),
                ],
                'links' => [
                    'first' => $registrations->url(1),
                    'last' => $registrations->url($registrations->lastPage()),
                    'prev' => $registrations->previousPageUrl(),
                    'next' => $registrations->nextPageUrl(),
                ],
            ]);
        }, 'Failed to retrieve event registrations.');
    }
}
