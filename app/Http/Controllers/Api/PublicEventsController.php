<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
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
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
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
        $event = Event::with(['organizer', 'location', 'tickets'])
            ->where('status', 'published')
            ->findOrFail($id);

        $resource = (new EventResource($event))->toArray(request());

        // Add ticket tiers for public view
        $resource['ticket_tiers'] = $event->tickets
            ->where('is_active', true)
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'name' => $ticket->name,
                    'description' => $ticket->description,
                    'price' => (float) $ticket->price_ugx,
                    'price_credits' => (float) $ticket->price_credits,
                    'is_free' => (bool) $ticket->is_free,
                    'quantity' => $ticket->quantity_total,
                    'available' => $ticket->quantity_available,
                    'max_per_order' => $ticket->max_per_order,
                    'sale_starts_at' => $ticket->sale_starts_at?->toIso8601String(),
                    'sale_ends_at' => $ticket->sale_ends_at?->toIso8601String(),
                    'is_sold_out' => $ticket->isSoldOut(),
                    'is_on_sale' => $ticket->isOnSale(),
                    'required_loyalty_tier' => $ticket->required_loyalty_tier,
                ];
            })->values();

        return response()->json(['data' => $resource]);
    }
}
