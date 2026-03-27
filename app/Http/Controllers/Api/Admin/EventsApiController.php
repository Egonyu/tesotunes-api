<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\User;
use App\Services\Events\EventCommissionSimulationService;
use App\Services\Events\EventAuditLogService;
use App\Services\Events\EventPayoutLedgerService;
use App\Services\Events\EventRevenueAnalyticsService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EventsApiController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly EventRevenueAnalyticsService $eventRevenueAnalyticsService,
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
        private readonly EventCommissionSimulationService $eventCommissionSimulationService,
        private readonly EventAuditLogService $eventAuditLogService,
    ) {}

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

            $events = Event::with(['organizer', 'location', 'tickets', 'artist.user', 'artists.user', 'attendees.ticket', 'ticketCases', 'payoutLedgerEntries'])
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
                'data' => $events->getCollection()
                    ->map(function (Event $event) use ($request) {
                        return [
                            ...(new EventResource($event))->toArray($request),
                            'risk' => $this->eventRevenueAnalyticsService->summarizeRisk($event),
                        ];
                    })
                    ->values(),
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
            $event = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'artists.user', 'location', 'tickets'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
            ]);
        }, 'Failed to retrieve event.');
    }

    public function commissionSimulation(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'organizer_user_id' => 'nullable|integer|exists:users,id',
                'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
                'currency' => 'nullable|string|max:10',
                'ticket_tiers' => 'required|array|min:1',
                'ticket_tiers.*.name' => 'nullable|string|max:120',
                'ticket_tiers.*.price' => 'nullable|numeric|min:0',
                'ticket_tiers.*.price_ugx' => 'nullable|numeric|min:0',
                'ticket_tiers.*.price_credits' => 'nullable|numeric|min:0',
                'ticket_tiers.*.quantity' => 'required|integer|min:1',
            ]);

            $selectedOrganizer = null;

            if (! empty($validated['organizer_user_id'])) {
                $selectedOrganizer = User::with('artist')->findOrFail((int) $validated['organizer_user_id']);
            } elseif (auth()->check()) {
                $selectedOrganizer = auth()->user()->loadMissing('artist');
            }

            return response()->json([
                'success' => true,
                'data' => $this->eventCommissionSimulationService->simulate(
                    organizer: $selectedOrganizer,
                    ticketTiers: $validated['ticket_tiers'],
                    ticketingMode: $validated['ticketing_mode'] ?? Event::TICKETING_MODE_TESOTUNES_MANAGED,
                    currency: $validated['currency'] ?? 'UGX',
                ),
            ]);
        }, 'Failed to simulate event commission.');
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
                'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
                'currency' => 'nullable|string|max:10',
                'attendee_limit' => 'nullable|integer|min:1',
                'is_featured' => 'nullable|boolean',
                'status' => 'nullable|in:draft,published,cancelled,completed,postponed',
                'cover_image' => 'nullable|file|image|max:5120',
                'ticket_tiers' => 'nullable',
                'category' => 'nullable|string',
                'organizer_user_id' => 'nullable|integer|exists:users,id',
                'artist_ids' => 'nullable|array',
                'artist_ids.*' => 'integer|exists:artists,id',
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

            $selectedOrganizer = null;

            if ($request->filled('organizer_user_id')) {
                $selectedOrganizer = User::with('artist')->findOrFail((int) $request->input('organizer_user_id'));
            } elseif (auth()->check()) {
                $selectedOrganizer = auth()->user()->loadMissing('artist');
            }

            $validated['uuid'] = Str::uuid();
            $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']).'-'.Str::random(6);
            $validated['organizer_id'] = $selectedOrganizer?->id;
            $validated['organizer_type'] = 'user';
            $validated['user_id'] = $selectedOrganizer?->id;
            $validated['artist_id'] = $selectedOrganizer?->artist?->id;
            $validated['status'] = $validated['status'] ?? 'draft';
            $validated['timezone'] = $validated['timezone'] ?? 'Africa/Nairobi';
            $validated['ticketing_mode'] = $validated['ticketing_mode']
                ?? (($validated['is_free'] ?? false) ? Event::TICKETING_MODE_FREE_RSVP : Event::TICKETING_MODE_TESOTUNES_MANAGED);
            $artistIds = collect($request->input('artist_ids', []))
                ->filter()
                ->map(fn ($artistId) => (int) $artistId)
                ->values();
            unset($validated['artist_ids']);
            unset($validated['organizer_user_id']);

            $event = Event::create($validated);

            if ($artistIds->isNotEmpty()) {
                $event->artists()->sync($artistIds->mapWithKeys(fn ($artistId, $index) => [
                    $artistId => ['sort_order' => $index],
                ])->all());

                if (! $event->artist_id) {
                    $event->update(['artist_id' => $artistIds->first()]);
                }
            }

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
                'data' => new EventResource($event->load(['organizer.artist', 'user.artist', 'artist.user', 'artists.user'])),
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
                'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
                'is_featured' => 'nullable|boolean',
                'event_type' => 'nullable|string',
                'currency' => 'nullable|string|max:10',
                'organizer_user_id' => 'nullable|integer|exists:users,id',
                'artist_ids' => 'nullable|array',
                'artist_ids.*' => 'integer|exists:artists,id',
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

            if (! array_key_exists('ticketing_mode', $validated) && array_key_exists('is_free', $validated)) {
                $validated['ticketing_mode'] = $validated['is_free']
                    ? Event::TICKETING_MODE_FREE_RSVP
                    : Event::TICKETING_MODE_TESOTUNES_MANAGED;
            }

            if ($request->filled('organizer_user_id')) {
                $selectedOrganizer = User::with('artist')->findOrFail((int) $request->input('organizer_user_id'));
                $validated['organizer_id'] = $selectedOrganizer->id;
                $validated['organizer_type'] = 'user';
                $validated['user_id'] = $selectedOrganizer->id;
                $validated['artist_id'] = $selectedOrganizer->artist?->id;
            }

            $artistIds = null;
            if ($request->has('artist_ids')) {
                $artistIds = collect($request->input('artist_ids', []))
                    ->filter()
                    ->map(fn ($artistId) => (int) $artistId)
                    ->values();
                $validated['artist_id'] = $artistIds->first() ?: null;
            }

            unset($validated['organizer_user_id']);
            unset($validated['artist_ids']);

            $event->update($validated);

            if ($artistIds !== null) {
                $event->artists()->sync($artistIds->mapWithKeys(fn ($artistId, $index) => [
                    $artistId => ['sort_order' => $index],
                ])->all());
            }

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
                'data' => new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'artists.user', 'location', 'tickets'])),
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
                'data' => new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets'])),
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
                'data' => new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets'])),
            ]);
        }, 'Failed to update event featured state.');
    }

    /**
     * GET /api/admin/events/{id}/analytics
     */
    public function analytics(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::with(['tickets', 'attendees.ticket', 'interestedUsers', 'payoutLedgerEntries'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->eventRevenueAnalyticsService->summarize($event),
            ]);
        }, 'Failed to retrieve event analytics.');
    }

    public function exportAnalytics(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $event = Event::with(['attendees', 'payoutLedgerEntries'])->findOrFail($id);
            $rows = $this->eventPayoutLedgerService->exportRowsForEvent($event);
            $filename = 'admin_event_payouts_'.$event->id.'.csv';
            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Tesotunes Admin Event Payout Export']);
            fputcsv($csv, ['Event', $event->title]);
            fputcsv($csv, []);
            fputcsv($csv, [
                'Order ID',
                'Payment Reference',
                'Payout Status',
                'Ticket Quantity',
                'Gross Revenue',
                'Customer Paid Total',
                'Tesotunes Fee Revenue',
                'Platform Commission',
                'Processing Fee',
                'Organizer Net Amount',
                'Fee Source',
                'Attribution Label',
                'Hold Status',
                'Hold Reason',
                'Occurred At',
                'Payout Ready At',
                'Paid Out At',
            ]);

            foreach ($rows as $row) {
                fputcsv($csv, [
                    $row['order_id'] ?? '',
                    $row['payment_reference'] ?? '',
                    $row['payout_status'] ?? '',
                    $row['ticket_quantity'] ?? 0,
                    $row['gross_revenue'] ?? 0,
                    $row['customer_paid_total'] ?? 0,
                    $row['tesotunes_fee_revenue'] ?? 0,
                    $row['platform_commission_amount'] ?? 0,
                    $row['processing_fee_amount'] ?? 0,
                    $row['organizer_net_amount'] ?? 0,
                    $row['fee_source'] ?? '',
                    $row['attribution_label'] ?? '',
                    $row['hold_status'] ?? '',
                    $row['hold_reason'] ?? '',
                    $row['occurred_at'] ?? '',
                    $row['payout_ready_at'] ?? '',
                    $row['paid_out_at'] ?? '',
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }, 'Failed to export event analytics.');
    }

    public function holdPayouts(Request $request, int $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $event = Event::with(['payoutLedgerEntries'])->findOrFail($id);
            $heldCount = $this->eventPayoutLedgerService->holdReadyEntries($event, $validated['reason'] ?? null, $request->user());
            if ($heldCount > 0) {
                $this->eventAuditLogService->log(
                    $request->user(),
                    $event,
                    'event_payouts_held',
                    [],
                    [
                        'held_entries' => $heldCount,
                        'reason' => $validated['reason'] ?? null,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => $heldCount > 0 ? 'Ready payouts placed on hold.' : 'No ready payouts were available to hold.',
                'data' => [
                    'held_entries' => $heldCount,
                    'analytics' => $this->eventRevenueAnalyticsService->summarize($event->fresh(['tickets', 'attendees.ticket', 'interestedUsers', 'payoutLedgerEntries'])),
                ],
            ]);
        }, 'Failed to hold payouts.');
    }

    public function releasePayouts(Request $request, int $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $validated = $request->validate([
                'note' => 'nullable|string|max:500',
            ]);

            $event = Event::with(['payoutLedgerEntries'])->findOrFail($id);
            $releasedCount = $this->eventPayoutLedgerService->releaseHeldEntries($event, $validated['note'] ?? null, $request->user());
            if ($releasedCount > 0) {
                $this->eventAuditLogService->log(
                    $request->user(),
                    $event,
                    'event_payouts_released',
                    [],
                    [
                        'released_entries' => $releasedCount,
                        'note' => $validated['note'] ?? null,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => $releasedCount > 0 ? 'Held payouts released back to ready state.' : 'No held payouts were available to release.',
                'data' => [
                    'released_entries' => $releasedCount,
                    'analytics' => $this->eventRevenueAnalyticsService->summarize($event->fresh(['tickets', 'attendees.ticket', 'interestedUsers', 'payoutLedgerEntries'])),
                ],
            ]);
        }, 'Failed to release payouts.');
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
