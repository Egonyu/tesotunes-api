<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtistEventsController extends Controller
{
    /**
     * GET /api/artist/events — list artist's own events
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $events = Event::with(['organizer', 'location', 'tickets'])
            ->where('organizer_id', $user->id)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderByDesc('starts_at')
            ->paginate($perPage);

        return EventResource::collection($events);
    }

    /**
     * POST /api/artist/events — create event
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'slug' => 'nullable|string|max:220',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'event_type' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'venue_name' => 'nullable|string',
            'venue_address' => 'nullable|string',
            'city' => 'nullable|string',
            'country' => 'nullable|string',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|string',
            'end_date' => 'nullable|date',
            'end_time' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'timezone' => 'nullable|string',
            'is_virtual' => 'nullable|boolean',
            'is_online' => 'nullable|boolean',
            'virtual_link' => 'nullable|string',
            'online_url' => 'nullable|string',
            'is_free' => 'nullable|boolean',
            'attendee_limit' => 'nullable|integer|min:1',
            'max_capacity' => 'nullable|integer|min:1',
            'min_age' => 'nullable|integer',
            'status' => 'nullable|in:draft,published',
            'cover_image' => 'nullable|file|image|max:5120',
            'ticket_tiers' => 'nullable|string', // JSON string
        ]);

        // Combine date+time if provided separately
        if (! isset($validated['starts_at']) && isset($validated['start_date'])) {
            $validated['starts_at'] = $validated['start_date'].' '.($validated['start_time'] ?? '00:00:00');
        }
        if (! isset($validated['ends_at']) && isset($validated['end_date'])) {
            $validated['ends_at'] = $validated['end_date'].' '.($validated['end_time'] ?? '23:59:59');
        }

        // Map frontend field names to DB fields
        if (isset($validated['is_online'])) {
            $validated['is_virtual'] = $validated['is_online'];
        }
        if (isset($validated['online_url'])) {
            $validated['virtual_link'] = $validated['online_url'];
        }
        if (isset($validated['max_capacity'])) {
            $validated['attendee_limit'] = $validated['max_capacity'];
        }

        // Handle image upload
        if ($request->hasFile('cover_image')) {
            $validated['artwork'] = StorageHelper::store($request->file('cover_image'), 'events/covers');
        }

        // Handle event_location creation
        $locationId = null;
        if (! empty($validated['venue_name']) && ! empty($validated['city'])) {
            $locationId = \DB::table('event_locations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $validated['venue_name'],
                'address' => $validated['venue_address'] ?? null,
                'city' => $validated['city'],
                'country' => $validated['country'] ?? 'UG',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Extract ticket tiers before cleaning
        $ticketTiers = null;
        if (isset($validated['ticket_tiers'])) {
            $ticketTiers = json_decode($validated['ticket_tiers'], true);
        }

        // Clean up non-model fields
        $nonModelFields = ['start_date', 'start_time', 'end_date', 'end_time', 'cover_image',
            'is_online', 'online_url', 'max_capacity', 'ticket_tiers', 'min_age', 'short_description'];
        foreach ($nonModelFields as $field) {
            unset($validated[$field]);
        }

        $validated['uuid'] = Str::uuid();
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']).'-'.Str::random(6);
        $validated['organizer_id'] = $user->id;
        $validated['organizer_type'] = 'user';
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['timezone'] = $validated['timezone'] ?? 'Africa/Kampala';
        if ($locationId) {
            $validated['event_location_id'] = $locationId;
        }

        $event = Event::create($validated);

        // Create ticket tiers if provided
        if ($ticketTiers && is_array($ticketTiers)) {
            foreach ($ticketTiers as $i => $tier) {
                EventTicket::create([
                    'uuid' => (string) Str::uuid(),
                    'event_id' => $event->id,
                    'name' => $tier['name'] ?? 'General',
                    'description' => $tier['description'] ?? null,
                    'price_ugx' => $tier['price'] ?? 0,
                    'price_credits' => $tier['price_credits'] ?? 0,
                    'is_free' => ($tier['price'] ?? 0) == 0,
                    'quantity_total' => $tier['quantity'] ?? null,
                    'max_per_order' => $tier['max_per_order'] ?? 10,
                    'sale_starts_at' => isset($tier['sale_starts_at']) ? $tier['sale_starts_at'] : null,
                    'sale_ends_at' => isset($tier['sale_ends_at']) ? $tier['sale_ends_at'] : null,
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        return response()->json([
            'message' => 'Event created successfully',
            'data' => new EventResource($event->load(['organizer', 'location', 'tickets'])),
        ], 201);
    }

    /**
     * GET /api/artist/events/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $event = Event::with(['organizer', 'location', 'tickets', 'attendees'])
            ->where('organizer_id', $user->id)
            ->findOrFail($id);

        $resource = (new EventResource($event))->toArray(request());
        $resource['ticket_tiers'] = $event->tickets->map(function ($t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price' => (float) $t->price_ugx,
                'price_credits' => (float) $t->price_credits,
                'is_free' => (bool) $t->is_free,
                'quantity' => $t->quantity_total,
                'sold' => (int) $t->quantity_sold,
                'available' => $t->quantity_available,
                'max_per_order' => $t->max_per_order,
                'is_active' => (bool) $t->is_active,
            ];
        });

        return response()->json(['data' => $resource]);
    }

    /**
     * PUT /api/artist/events/{id}
     */
    public function update(Request $request, int $id)
    {
        $user = auth()->user();
        $event = Event::where('organizer_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|string',
            'end_date' => 'nullable|date',
            'end_time' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'timezone' => 'nullable|string',
            'venue_name' => 'nullable|string',
            'venue_address' => 'nullable|string',
            'city' => 'nullable|string',
            'country' => 'nullable|string',
            'is_virtual' => 'nullable|boolean',
            'virtual_link' => 'nullable|string',
            'attendee_limit' => 'nullable|integer|min:1',
            'status' => 'nullable|in:draft,published',
            'cover_image' => 'nullable|file|image|max:5120',
        ]);

        if (! isset($validated['starts_at']) && isset($validated['start_date'])) {
            $validated['starts_at'] = $validated['start_date'].' '.($validated['start_time'] ?? '00:00:00');
        }
        if (! isset($validated['ends_at']) && isset($validated['end_date'])) {
            $validated['ends_at'] = $validated['end_date'].' '.($validated['end_time'] ?? '23:59:59');
        }

        if ($request->hasFile('cover_image')) {
            if ($event->artwork) {
                StorageHelper::delete($event->artwork);
            }
            $validated['artwork'] = StorageHelper::store($request->file('cover_image'), 'events/covers');
        }

        unset($validated['start_date'], $validated['start_time'], $validated['end_date'], $validated['end_time'], $validated['cover_image']);

        $event->update($validated);

        return response()->json([
            'message' => 'Event updated successfully',
            'data' => new EventResource($event->fresh()->load(['organizer', 'location'])),
        ]);
    }

    /**
     * DELETE /api/artist/events/{id}
     */
    public function destroy(int $id)
    {
        $user = auth()->user();
        $event = Event::where('organizer_id', $user->id)->findOrFail($id);

        if ($event->artwork) {
            StorageHelper::delete($event->artwork);
        }

        $event->delete();

        return response()->json(['message' => 'Event deleted successfully']);
    }

    /**
     * GET /api/artist/events/{id}/analytics
     */
    public function analytics(int $id)
    {
        $user = auth()->user();
        $event = Event::with(['tickets', 'attendees'])
            ->where('organizer_id', $user->id)
            ->findOrFail($id);

        $totalRevenue = $event->attendees->where('status', 'confirmed')->sum('price_paid_ugx');
        $totalRevenueCredits = $event->attendees->where('status', 'confirmed')->sum('price_paid_credits');
        $ticketsSold = $event->tickets->sum('quantity_sold');
        $checkIns = $event->attendees->whereNotNull('checked_in_at')->count();

        $byTier = $event->tickets->map(function ($t) {
            return [
                'name' => $t->name,
                'sold' => (int) $t->quantity_sold,
                'total' => $t->quantity_total,
                'revenue' => (float) ($t->quantity_sold * $t->price_ugx),
            ];
        });

        // Revenue by date (last 30 days)
        $byDate = $event->attendees()
            ->where('status', 'confirmed')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, SUM(price_paid_ugx) as revenue, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => [
                'tickets_sold' => $ticketsSold,
                'revenue' => $totalRevenue,
                'revenue_credits' => $totalRevenueCredits,
                'check_ins' => $checkIns,
                'by_tier' => $byTier,
                'by_date' => $byDate,
            ],
        ]);
    }
}
