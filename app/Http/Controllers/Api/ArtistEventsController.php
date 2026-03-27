<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventDiscountCode;
use App\Models\EventPromotionRequest;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\EventTicketCase;
use App\Models\EventTicketChannelAllocation;
use App\Models\User;
use App\Services\Events\EventCommissionSimulationService;
use App\Services\Events\EventAuditLogService;
use App\Services\Events\EventPayoutLedgerService;
use App\Services\Events\EventRevenueAnalyticsService;
use App\Services\Events\EventTicketCaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ArtistEventsController extends Controller
{
    public function __construct(
        private readonly EventRevenueAnalyticsService $eventRevenueAnalyticsService,
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
        private readonly EventTicketCaseService $eventTicketCaseService,
        private readonly EventCommissionSimulationService $eventCommissionSimulationService,
        private readonly EventAuditLogService $eventAuditLogService,
    ) {}

    /**
     * GET /api/artist/events — list artist's own events
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $events = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets.channelAllocations', 'staffMembers.user', 'discountCodes', 'promotionRequests.requestedBy', 'promotionRequests.moderatedBy'])
            ->ownedByUser($user)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderByDesc('starts_at')
            ->paginate($perPage);

        return EventResource::collection($events);
    }

    public function commissionSimulation(Request $request)
    {
        $validated = $request->validate([
            'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
            'currency' => 'nullable|string|max:10',
            'ticket_tiers' => 'required|array|min:1',
            'ticket_tiers.*.name' => 'nullable|string|max:120',
            'ticket_tiers.*.price' => 'nullable|numeric|min:0',
            'ticket_tiers.*.price_ugx' => 'nullable|numeric|min:0',
            'ticket_tiers.*.price_credits' => 'nullable|numeric|min:0',
            'ticket_tiers.*.quantity' => 'required|integer|min:1',
        ]);

        return response()->json([
            'data' => $this->eventCommissionSimulationService->simulate(
                organizer: $request->user()?->loadMissing('artist'),
                ticketTiers: $validated['ticket_tiers'],
                ticketingMode: $validated['ticketing_mode'] ?? Event::TICKETING_MODE_TESOTUNES_MANAGED,
                currency: $validated['currency'] ?? 'UGX',
            ),
        ]);
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
            'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
            'attendee_limit' => 'nullable|integer|min:1',
            'max_capacity' => 'nullable|integer|min:1',
            'min_age' => 'nullable|integer',
            'registration_deadline' => 'nullable|date',
            'refund_policy' => 'nullable|string|max:2000',
            'cancellation_policy' => 'nullable|string|max:2000',
            'requirements' => 'nullable',
            'contact_info' => 'nullable',
            'website' => 'nullable|url|max:255',
            'social_links' => 'nullable',
            'marketing_settings' => 'nullable',
            'status' => 'nullable|in:draft,published',
            'cover_image' => 'nullable|file|image|max:5120',
            'ticket_tiers' => 'nullable|string', // JSON string
        ]);

        $validated['requirements'] = $this->normalizeRequirements($request->input('requirements', $validated['requirements'] ?? null));
        $validated['contact_info'] = $this->normalizeContactInfo($request->input('contact_info', $validated['contact_info'] ?? null));
        $validated['social_links'] = $this->normalizeJsonObject($request->input('social_links', $validated['social_links'] ?? null));
        $validated['marketing_settings'] = $this->normalizeMarketingSettings($request->input('marketing_settings', $validated['marketing_settings'] ?? null));

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
        $validated['user_id'] = $user->id;
        $validated['artist_id'] = $user->artist?->id;
        $validated['organizer_type'] = 'user';
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['timezone'] = $validated['timezone'] ?? 'Africa/Kampala';
        $validated['ticketing_mode'] = $validated['ticketing_mode']
            ?? (($validated['is_free'] ?? false) ? Event::TICKETING_MODE_FREE_RSVP : Event::TICKETING_MODE_TESOTUNES_MANAGED);
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
            'data' => new EventResource($event->load(['organizer', 'location', 'tickets', 'staffMembers.user', 'discountCodes', 'promotionRequests.requestedBy', 'promotionRequests.moderatedBy'])),
        ], 201);
    }

    /**
     * GET /api/artist/events/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $event = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets.channelAllocations', 'attendees.ticket', 'staffMembers.user', 'discountCodes', 'promotionRequests.requestedBy', 'promotionRequests.moderatedBy'])
            ->ownedByUser($user)
            ->findOrFail($id);

        $resource = (new EventResource($event))->toArray(request());
        $resource['attendees'] = TicketResource::collection($event->attendees)->resolve();
        $resource['payout_center'] = $this->buildPayoutCenter($user, $event);

        return response()->json(['data' => $resource]);
    }

    public function storePromotionRequest(Request $request, int $id)
    {
        $user = $request->user();

        $event = Event::with(['promotionRequests'])
            ->ownedByUser($user)
            ->findOrFail($id);

        $validated = $request->validate([
            'promotion_slug' => 'nullable|string|max:255',
            'promotion_title' => 'required|string|max:255',
            'promotion_type' => 'nullable|string|max:120',
            'promotion_platform' => 'nullable|string|max:120',
            'price_credits' => 'nullable|numeric|min:0',
            'price_ugx' => 'nullable|numeric|min:0',
            'request_notes' => 'nullable|string|max:2000',
            'featured_image_url' => 'nullable|url|max:2048',
            'payload' => 'nullable|array',
        ]);

        $existingPending = $event->promotionRequests
            ->first(function (EventPromotionRequest $promotionRequest) use ($validated) {
                return $promotionRequest->status === EventPromotionRequest::STATUS_PENDING
                    && $promotionRequest->promotion_slug === ($validated['promotion_slug'] ?? null)
                    && $promotionRequest->promotion_title === $validated['promotion_title'];
            });

        if ($existingPending) {
            return response()->json([
                'success' => true,
                'message' => 'A pending moderation request already exists for this promotion package.',
                'data' => $this->serializePromotionRequest($existingPending->loadMissing('requestedBy', 'moderatedBy')),
            ]);
        }

        $promotionRequest = EventPromotionRequest::create([
            'event_id' => $event->id,
            'requested_by_user_id' => $user->id,
            'promotion_slug' => $validated['promotion_slug'] ?? null,
            'promotion_title' => $validated['promotion_title'],
            'promotion_type' => $validated['promotion_type'] ?? null,
            'promotion_platform' => $validated['promotion_platform'] ?? null,
            'price_credits' => (float) ($validated['price_credits'] ?? 0),
            'price_ugx' => (float) ($validated['price_ugx'] ?? 0),
            'request_notes' => $validated['request_notes'] ?? null,
            'featured_image_url' => $validated['featured_image_url'] ?? null,
            'payload' => $validated['payload'] ?? [],
            'status' => EventPromotionRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Promotion request submitted for Tesotunes review.',
            'data' => $this->serializePromotionRequest($promotionRequest->loadMissing('requestedBy', 'moderatedBy')),
        ], 201);
    }

    /**
     * PUT /api/artist/events/{id}
     */
    public function update(Request $request, int $id)
    {
        $user = auth()->user();
        $event = Event::ownedByUser($user)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
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
            'is_free' => 'nullable|boolean',
            'attendee_limit' => 'nullable|integer|min:1',
            'ticketing_mode' => 'nullable|in:tesotunes_managed,hybrid,external_only,free_rsvp',
            'registration_deadline' => 'nullable|date',
            'refund_policy' => 'nullable|string|max:2000',
            'cancellation_policy' => 'nullable|string|max:2000',
            'requirements' => 'nullable',
            'contact_info' => 'nullable',
            'website' => 'nullable|url|max:255',
            'social_links' => 'nullable',
            'marketing_settings' => 'nullable',
            'status' => 'nullable|in:draft,published',
            'cover_image' => 'nullable|file|image|max:5120',
        ]);

        if ($request->exists('requirements')) {
            $validated['requirements'] = $this->normalizeRequirements($request->input('requirements'));
        }
        if ($request->exists('contact_info')) {
            $validated['contact_info'] = $this->normalizeContactInfo($request->input('contact_info'));
        }
        if ($request->exists('social_links')) {
            $validated['social_links'] = $this->normalizeJsonObject($request->input('social_links'));
        }
        if ($request->exists('marketing_settings')) {
            $validated['marketing_settings'] = $this->normalizeMarketingSettings($request->input('marketing_settings'));
        }

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

        if (! array_key_exists('ticketing_mode', $validated) && array_key_exists('is_free', $validated)) {
            $validated['ticketing_mode'] = $validated['is_free']
                ? Event::TICKETING_MODE_FREE_RSVP
                : Event::TICKETING_MODE_TESOTUNES_MANAGED;
        }

        $oldMarketingSettings = $event->marketing_settings ?? [];
        $event->update($validated);

        if (array_key_exists('marketing_settings', $validated)) {
            $this->eventAuditLogService->log(
                $user,
                $event,
                'event_campaign_settings_updated',
                ['marketing_settings' => $oldMarketingSettings],
                ['marketing_settings' => $event->fresh()->marketing_settings ?? []],
            );
        }

        return response()->json([
            'message' => 'Event updated successfully',
            'data' => new EventResource($event->fresh()->load(['organizer', 'location', 'staffMembers.user', 'discountCodes'])),
        ]);
    }

    public function storeDiscountCode(Request $request, int $id)
    {
        $user = auth()->user();
        $event = Event::with(['tickets', 'discountCodes'])->ownedByUser($user)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
            'code' => 'required|string|max:80',
            'discount_type' => 'required|in:percentage,fixed_amount',
            'discount_value' => 'required|numeric|min:0.01',
            'max_discount_ugx' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'min_order_amount_ugx' => 'nullable|numeric|min:0',
            'applies_to_ticket_ids' => 'nullable|array',
            'applies_to_ticket_ids.*' => 'integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'nullable|boolean',
        ]);

        $ticketIds = collect($validated['applies_to_ticket_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();

        if ($ticketIds->isNotEmpty()) {
            $validTicketIds = $event->tickets->pluck('id');
            abort_unless($ticketIds->diff($validTicketIds)->isEmpty(), 422, 'Selected ticket tiers must belong to this event.');
        }

        $discountCode = EventDiscountCode::updateOrCreate(
            [
                'event_id' => $event->id,
                'code' => strtoupper(trim((string) $validated['code'])),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'] ?? null,
                'discount_type' => $validated['discount_type'],
                'discount_value' => $validated['discount_value'],
                'max_discount_ugx' => $validated['max_discount_ugx'] ?? null,
                'usage_limit' => $validated['usage_limit'] ?? null,
                'min_order_amount_ugx' => $validated['min_order_amount_ugx'] ?? null,
                'applies_to_ticket_ids' => $ticketIds->all(),
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ],
        );

        $this->eventAuditLogService->log(
            $user,
            $event,
            'event_discount_code_saved',
            [],
            [
                'discount_code_id' => $discountCode->id,
                'code' => $discountCode->code,
                'discount_type' => $discountCode->discount_type,
                'discount_value' => (float) $discountCode->discount_value,
                'is_active' => (bool) $discountCode->is_active,
            ]
        );

        return response()->json([
            'message' => 'Discount code saved successfully.',
            'data' => (new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])))->toArray($request),
        ], 201);
    }

    public function deleteDiscountCode(int $id, int $discountId)
    {
        $user = auth()->user();
        $event = Event::ownedByUser($user)->findOrFail($id);
        $discountCode = $event->discountCodes()->findOrFail($discountId);
        $oldValues = [
            'discount_code_id' => $discountCode->id,
            'code' => $discountCode->code,
            'discount_type' => $discountCode->discount_type,
            'discount_value' => (float) $discountCode->discount_value,
            'is_active' => (bool) $discountCode->is_active,
        ];
        $discountCode->delete();

        $this->eventAuditLogService->log(
            $user,
            $event,
            'event_discount_code_deleted',
            $oldValues,
            []
        );

        return response()->json([
            'message' => 'Discount code removed successfully.',
            'data' => (new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])))->toArray(request()),
        ]);
    }

    public function addStaff(Request $request, int $id)
    {
        $user = auth()->user();
        $event = Event::with(['staffMembers.user', 'organizer.artist', 'user.artist', 'artist.user'])
            ->ownedByUser($user)
            ->findOrFail($id);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'user_email' => 'nullable|email',
            'role' => 'required|in:finance,check_in_staff,promoter,analyst',
            'notes' => 'nullable|string|max:500',
        ]);

        $staffUser = null;

        if (! empty($validated['user_id'])) {
            $staffUser = User::find($validated['user_id']);
        } elseif (! empty($validated['user_email'])) {
            $staffUser = User::where('email', $validated['user_email'])->first();
        }

        if (! $staffUser) {
            return response()->json([
                'message' => 'Staff user was not found. Ask them to create a Tesotunes account first.',
            ], 422);
        }

        if ($staffUser->id === $event->canonicalOrganizerId()) {
            return response()->json([
                'message' => 'The organizer is already the primary owner of this event.',
            ], 422);
        }

        EventStaffMember::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $staffUser->id],
            [
                'uuid' => (string) Str::uuid(),
                'invited_by_user_id' => $user->id,
                'role' => $validated['role'],
                'notes' => $validated['notes'] ?? null,
                'is_active' => true,
            ],
        );

        return response()->json([
            'message' => 'Event staff member added successfully.',
            'data' => (new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])))->toArray($request),
        ], 201);
    }

    public function removeStaff(int $id, int $staffId)
    {
        $user = auth()->user();
        $event = Event::ownedByUser($user)->findOrFail($id);
        $staffMember = $event->staffMembers()->findOrFail($staffId);
        $staffMember->delete();

        return response()->json([
            'message' => 'Event staff member removed successfully.',
            'data' => (new EventResource($event->fresh()->load(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])))->toArray(request()),
        ]);
    }

    public function checkInLookup(Request $request, int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:150',
        ]);

        $query = trim($validated['query']);

        $matches = EventAttendee::with(['ticket', 'user'])
            ->where('event_id', $event->id)
            ->where(function ($builder) use ($query) {
                $builder->where('confirmation_code', 'like', '%'.$query.'%')
                    ->orWhere('attendee_name', 'like', '%'.$query.'%')
                    ->orWhere('attendee_email', 'like', '%'.$query.'%')
                    ->orWhere('attendee_phone', 'like', '%'.$query.'%');
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (EventAttendee $attendee) => $this->serializeCheckInAttendee($attendee));

        return response()->json([
            'data' => [
                'event_id' => $event->id,
                'query' => $query,
                'matches' => $matches,
            ],
        ]);
    }

    public function checkInAttendee(Request $request, int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'ticket_number' => 'required|string',
            'notes' => 'nullable|string|max:500',
            'allow_duplicate' => 'nullable|boolean',
        ]);

        $attendee = EventAttendee::with(['ticket', 'user'])
            ->where('event_id', $event->id)
            ->where('confirmation_code', $validated['ticket_number'])
            ->first();

        if (! $attendee) {
            return response()->json([
                'message' => 'Ticket not found for this event.',
            ], 404);
        }

        if ($attendee->status === EventAttendee::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This ticket has been cancelled.',
                'data' => $this->serializeCheckInAttendee($attendee),
            ], 422);
        }

        if ($attendee->checked_in_at && ! ($validated['allow_duplicate'] ?? false)) {
            return response()->json([
                'message' => 'Ticket already checked in.',
                'data' => [
                    ...$this->serializeCheckInAttendee($attendee),
                    'duplicate_warning' => true,
                ],
            ], 422);
        }

        $metadata = $attendee->attendee_metadata ?? [];
        $doorNotes = array_values(array_filter([
            ...($metadata['door_notes'] ?? []),
            ! empty($validated['notes']) ? [
                'note' => $validated['notes'],
                'created_at' => now()->toIso8601String(),
                'created_by_user_id' => $user->id,
                'type' => $attendee->checked_in_at ? 'duplicate_override' : 'check_in',
            ] : null,
        ]));

        $attendee->forceFill([
            'checked_in_at' => $attendee->checked_in_at ?? now(),
            'attended_at' => now(),
            'status' => EventAttendee::STATUS_ATTENDED,
            'checked_in_by_user_id' => $user->id,
            'notes' => $validated['notes'] ?? $attendee->notes,
            'attendee_metadata' => [
                ...$metadata,
                'door_notes' => $doorNotes,
                'last_check_in_by_user_id' => $user->id,
                'last_check_in_override' => (bool) ($attendee->checked_in_at && ($validated['allow_duplicate'] ?? false)),
            ],
        ])->save();

        return response()->json([
            'message' => $attendee->wasChanged('checked_in_at') ? 'Ticket checked in successfully.' : 'Duplicate check-in recorded with override.',
            'data' => [
                ...$this->serializeCheckInAttendee($attendee->fresh(['ticket', 'user'])),
                'duplicate_warning' => false,
            ],
        ]);
    }

    /**
     * DELETE /api/artist/events/{id}
     */
    public function destroy(int $id)
    {
        $user = auth()->user();
        $event = Event::ownedByUser($user)->findOrFail($id);

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
        $event = Event::with(['tickets', 'attendees.ticket', 'interestedUsers', 'payoutLedgerEntries', 'ticketCases'])
            ->ownedByUser($user)
            ->findOrFail($id);

        return response()->json([
            'data' => $this->eventRevenueAnalyticsService->summarize($event),
        ]);
    }

    public function ticketCases(int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);

        $cases = EventTicketCase::with(['attendee.ticket', 'requestedBy', 'resolvedBy'])
            ->where('event_id', $event->id)
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EventTicketCase $case) => $this->serializeTicketCase($case));

        return response()->json([
            'data' => $cases,
        ]);
    }

    public function resolveTicketCase(Request $request, int $id, int $caseId)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'resolution_notes' => 'nullable|string|max:2000',
            'approved_refund_amount' => 'nullable|numeric|min:0',
        ]);

        $case = EventTicketCase::with(['attendee.ticket', 'payment'])->findOrFail($caseId);
        $resolved = $this->eventTicketCaseService->resolveCase($user, $event, $case, $validated);

        return response()->json([
            'message' => $validated['decision'] === 'approve'
                ? 'Ticket support case approved successfully.'
                : 'Ticket support case rejected successfully.',
            'data' => $this->serializeTicketCase($resolved),
        ]);
    }

    public function offlineSales(int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);

        $orders = EventAttendee::with('ticket')
            ->where('event_id', $event->id)
            ->where('payment_method', 'manual_offline')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (EventAttendee $attendee) => data_get($attendee->attendee_metadata, 'order_id') ?? ('offline-attendee-'.$attendee->id))
            ->map(fn ($group, $orderId) => $this->serializeOfflineSaleOrder($group, (string) $orderId))
            ->values();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function externalAllocations(int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);

        $allocations = EventTicketChannelAllocation::with(['ticket', 'loggedBy', 'releasedBy'])
            ->where('event_id', $event->id)
            ->where('channel', EventTicketChannelAllocation::CHANNEL_EXTERNAL)
            ->latest()
            ->get()
            ->map(fn (EventTicketChannelAllocation $allocation) => $this->serializeExternalAllocation($allocation))
            ->values();

        return response()->json([
            'data' => $allocations,
        ]);
    }

    public function storeExternalAllocation(Request $request, int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $this->abortUnlessCanManageExternalAllocations($user, $event);

        $validated = $request->validate([
            'ticket_tier_id' => 'required|integer|exists:event_tickets,id',
            'quantity' => 'required|integer|min:1|max:100000',
            'channel_label' => 'required|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ]);

        $allocation = null;

        \DB::transaction(function () use ($event, $validated, $user, &$allocation) {
            /** @var EventTicket $ticket */
            $ticket = EventTicket::query()
                ->with('channelAllocations')
                ->lockForUpdate()
                ->findOrFail($validated['ticket_tier_id']);

            abort_unless($ticket->event_id === $event->id, 422, 'Ticket tier must belong to this event.');
            abort_if($ticket->quantity_total !== null && $ticket->quantity_available < (int) $validated['quantity'], 422, 'Not enough remaining capacity in this ticket tier.');

            $allocation = EventTicketChannelAllocation::create([
                'uuid' => (string) Str::uuid(),
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'logged_by_user_id' => $user->id,
                'channel' => EventTicketChannelAllocation::CHANNEL_EXTERNAL,
                'channel_label' => trim((string) $validated['channel_label']),
                'quantity' => (int) $validated['quantity'],
                'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
            ]);
        });

        $allocation?->load(['ticket.channelAllocations', 'loggedBy', 'releasedBy']);

        return response()->json([
            'message' => 'External capacity allocation saved successfully.',
            'data' => $this->serializeExternalAllocation($allocation),
        ], 201);
    }

    public function releaseExternalAllocation(Request $request, int $id, int $allocationId)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $this->abortUnlessCanManageExternalAllocations($user, $event);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $allocation = EventTicketChannelAllocation::with(['ticket.channelAllocations', 'loggedBy', 'releasedBy'])
            ->where('event_id', $event->id)
            ->where('channel', EventTicketChannelAllocation::CHANNEL_EXTERNAL)
            ->findOrFail($allocationId);

        if (! $allocation->released_at) {
            $allocation->release($user, trim((string) ($validated['reason'] ?? '')) ?: null);
        }

        $allocation->refresh()->load(['ticket.channelAllocations', 'loggedBy', 'releasedBy']);

        return response()->json([
            'message' => 'External capacity allocation released successfully.',
            'data' => $this->serializeExternalAllocation($allocation),
        ]);
    }

    public function storeOfflineSale(Request $request, int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'ticket_tier_id' => 'required|integer|exists:event_tickets,id',
            'quantity' => 'required|integer|min:1|max:100',
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
            'unit_price_ugx' => 'nullable|numeric|min:0',
            'sale_source' => 'nullable|in:printed_ticket,door_sale,phone_booking,complimentary',
            'notes' => 'nullable|string|max:500',
        ]);

        /** @var EventTicket $ticket */
        $ticket = EventTicket::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->findOrFail($validated['ticket_tier_id']);

        $quantity = (int) $validated['quantity'];
        $available = $ticket->quantity_available;
        if ($available !== null && $available < $quantity) {
            return response()->json([
                'message' => "Only {$available} tickets remain for {$ticket->name}.",
            ], 422);
        }

        $holderName = trim((string) ($validated['holder_name'] ?? '')) ?: 'Offline Buyer';
        $holderEmail = trim((string) ($validated['holder_email'] ?? '')) ?: null;
        $holderPhone = trim((string) ($validated['holder_phone'] ?? '')) ?: null;
        $saleSource = $validated['sale_source'] ?? 'printed_ticket';
        $unitPrice = array_key_exists('unit_price_ugx', $validated)
            ? (float) $validated['unit_price_ugx']
            : (float) ($ticket->price_ugx ?? 0);
        $notes = trim((string) ($validated['notes'] ?? '')) ?: null;
        $orderId = 'OFFLINE-'.strtoupper(Str::random(10));

        $createdAttendees = collect();
        $offlineBuyerUser = new User([
            'name' => $holderName,
            'display_name' => $holderName,
            'full_name' => $holderName,
            'username' => 'offline_'.Str::lower(Str::random(10)),
            'email' => 'offline+'.Str::lower(Str::random(18)).'@tesotunes.local',
            'password' => Hash::make(Str::random(40)),
            'phone' => $holderPhone,
            'settings' => [
                'offline_ticket_holder' => true,
                'offline_contact_email' => $holderEmail,
                'offline_sale_source' => $saleSource,
            ],
        ]);
        $offlineBuyerUser->forceFill([
            'email_verified_at' => now(),
        ])->save();

        \DB::transaction(function () use ($event, $ticket, $quantity, $holderName, $holderEmail, $holderPhone, $saleSource, $unitPrice, $notes, $orderId, $user, $offlineBuyerUser, &$createdAttendees) {
            for ($index = 0; $index < $quantity; $index++) {
                $attendeeName = $quantity === 1
                    ? $holderName
                    : sprintf('%s #%d', $holderName, $index + 1);

                $confirmationCode = 'OFL-'.strtoupper(Str::random(8));

                $createdAttendees->push(EventAttendee::create([
                    'uuid' => (string) Str::uuid(),
                    'confirmation_code' => $confirmationCode,
                    'event_id' => $event->id,
                    'ticket_id' => $ticket->id,
                    'user_id' => $offlineBuyerUser->id,
                    'attendee_name' => $attendeeName,
                    'attendee_email' => $holderEmail,
                    'attendee_phone' => $holderPhone,
                    'price_paid_ugx' => $unitPrice,
                    'price_paid_credits' => 0,
                    'payment_method' => 'manual_offline',
                    'payment_reference' => $orderId,
                    'status' => EventAttendee::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'quantity' => 1,
                    'amount_paid' => $unitPrice,
                    'payment_status' => 'completed',
                    'qr_code' => base64_encode(json_encode([
                        'ticket' => $confirmationCode,
                        'event' => $event->id,
                        'type' => $ticket->name,
                        'offline_sale' => true,
                    ])),
                    'attendee_metadata' => [
                        'order_id' => $orderId,
                        'sales_channel' => 'manual_offline',
                        'ticket_source' => 'manual_offline',
                        'offline_sale' => true,
                        'is_manual_ticket' => true,
                        'offline_sale_source' => $saleSource,
                        'logged_by_user_id' => $user->id,
                        'logged_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ],
                    'notes' => $notes,
                ]));
            }

            $ticket->sell($quantity);
        });

        return response()->json([
            'message' => 'Offline ticket sale logged successfully.',
            'data' => $this->serializeOfflineSaleOrder($createdAttendees, $orderId),
        ], 201);
    }

    public function storePrintedTicketImport(Request $request, int $id)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'ticket_tier_id' => 'required|integer|exists:event_tickets,id',
            'codes' => 'required',
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
            'unit_price_ugx' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'validation_notes' => 'nullable|string|max:500',
        ]);

        /** @var EventTicket $ticket */
        $ticket = EventTicket::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->findOrFail($validated['ticket_tier_id']);

        $codes = collect(is_array($validated['codes']) ? $validated['codes'] : preg_split('/\r\n|\r|\n|,/', (string) $validated['codes']))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        abort_if($codes->isEmpty(), 422, 'Add at least one printed ticket code to import.');

        $available = $ticket->quantity_available;
        if ($available !== null && $available < $codes->count()) {
            return response()->json([
                'message' => "Only {$available} tickets remain for {$ticket->name}.",
            ], 422);
        }

        $existingCodes = EventAttendee::query()
            ->whereIn('confirmation_code', $codes->all())
            ->pluck('confirmation_code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->all();

        abort_if(! empty($existingCodes), 422, 'These printed ticket codes already exist: '.implode(', ', array_slice($existingCodes, 0, 10)));

        $holderName = trim((string) ($validated['holder_name'] ?? '')) ?: 'Printed Ticket Buyer';
        $holderEmail = trim((string) ($validated['holder_email'] ?? '')) ?: null;
        $holderPhone = trim((string) ($validated['holder_phone'] ?? '')) ?: null;
        $unitPrice = array_key_exists('unit_price_ugx', $validated)
            ? (float) $validated['unit_price_ugx']
            : (float) ($ticket->price_ugx ?? 0);
        $notes = trim((string) ($validated['notes'] ?? '')) ?: null;
        $validationNotes = trim((string) ($validated['validation_notes'] ?? '')) ?: null;
        $orderId = 'PRINTED-'.strtoupper(Str::random(10));

        $createdAttendees = collect();
        $offlineBuyerUser = new User([
            'name' => $holderName,
            'display_name' => $holderName,
            'full_name' => $holderName,
            'username' => 'printed_'.Str::lower(Str::random(10)),
            'email' => 'printed+'.Str::lower(Str::random(18)).'@tesotunes.local',
            'password' => Hash::make(Str::random(40)),
            'phone' => $holderPhone,
            'country' => 'UG',
            'status' => 'active',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $offlineBuyerUser->save();

        \DB::transaction(function () use ($event, $ticket, $codes, $holderName, $holderEmail, $holderPhone, $unitPrice, $notes, $validationNotes, $orderId, $user, $offlineBuyerUser, &$createdAttendees) {
            foreach ($codes as $index => $code) {
                $attendeeName = $codes->count() === 1
                    ? $holderName
                    : sprintf('%s #%d', $holderName, $index + 1);

                $createdAttendees->push(EventAttendee::create([
                    'uuid' => (string) Str::uuid(),
                    'confirmation_code' => $code,
                    'event_id' => $event->id,
                    'ticket_id' => $ticket->id,
                    'user_id' => $offlineBuyerUser->id,
                    'attendee_name' => $attendeeName,
                    'attendee_email' => $holderEmail,
                    'attendee_phone' => $holderPhone,
                    'price_paid_ugx' => $unitPrice,
                    'price_paid_credits' => 0,
                    'payment_method' => 'manual_offline',
                    'payment_reference' => $orderId,
                    'status' => EventAttendee::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'quantity' => 1,
                    'amount_paid' => $unitPrice,
                    'payment_status' => 'completed',
                    'qr_code' => base64_encode(json_encode([
                        'ticket' => $code,
                        'event' => $event->id,
                        'type' => $ticket->name,
                        'printed_ticket' => true,
                    ])),
                    'attendee_metadata' => [
                        'order_id' => $orderId,
                        'sales_channel' => 'manual_offline',
                        'ticket_source' => 'printed_ticket',
                        'offline_sale_source' => 'printed_ticket',
                        'offline_sale' => true,
                        'is_manual_ticket' => true,
                        'printed_ticket_import' => true,
                        'validation_notes' => $validationNotes,
                        'logged_by_user_id' => $user->id,
                        'logged_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ],
                    'notes' => $notes,
                ]));
            }

            $ticket->sell($codes->count());
        });

        return response()->json([
            'message' => 'Printed ticket codes imported successfully.',
            'data' => $this->serializeOfflineSaleOrder($createdAttendees, $orderId),
        ], 201);
    }

    public function syncPrintedTicketImport(Request $request, int $id, string $orderId)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
            'validation_notes' => 'nullable|string|max:500',
        ]);

        $orderAttendees = EventAttendee::with('ticket')
            ->where('event_id', $event->id)
            ->where('payment_method', 'manual_offline')
            ->get()
            ->filter(function (EventAttendee $attendee) use ($orderId) {
                return data_get($attendee->attendee_metadata, 'order_id') === $orderId
                    && (bool) data_get($attendee->attendee_metadata, 'printed_ticket_import', false);
            })
            ->values();

        if ($orderAttendees->isEmpty()) {
            return response()->json(['message' => 'Printed ticket batch not found.'], 404);
        }

        if ($orderAttendees->contains(fn (EventAttendee $attendee) => $attendee->isCancelled())) {
            return response()->json(['message' => 'Voided printed ticket batches cannot be synced.'], 422);
        }

        $holderName = array_key_exists('holder_name', $validated)
            ? (trim((string) $validated['holder_name']) ?: null)
            : null;
        $holderEmail = array_key_exists('holder_email', $validated)
            ? (trim((string) $validated['holder_email']) ?: null)
            : null;
        $holderPhone = array_key_exists('holder_phone', $validated)
            ? (trim((string) $validated['holder_phone']) ?: null)
            : null;
        $notes = array_key_exists('notes', $validated)
            ? (trim((string) $validated['notes']) ?: null)
            : null;
        $validationNotes = array_key_exists('validation_notes', $validated)
            ? (trim((string) $validated['validation_notes']) ?: null)
            : null;
        $hasHolderName = array_key_exists('holder_name', $validated);
        $hasHolderEmail = array_key_exists('holder_email', $validated);
        $hasHolderPhone = array_key_exists('holder_phone', $validated);
        $hasNotes = array_key_exists('notes', $validated);
        $hasValidationNotes = array_key_exists('validation_notes', $validated);
        $batchCount = $orderAttendees->count();

        \DB::transaction(function () use ($orderAttendees, $holderName, $holderEmail, $holderPhone, $notes, $validationNotes, $hasHolderName, $hasHolderEmail, $hasHolderPhone, $hasNotes, $hasValidationNotes, $batchCount, $user) {
            foreach ($orderAttendees as $index => $attendee) {
                $metadata = $attendee->attendee_metadata ?? [];
                $nextHolderName = $hasHolderName
                    ? (($holderName !== null && $holderName !== '')
                        ? ($batchCount === 1 ? $holderName : sprintf('%s #%d', $holderName, $index + 1))
                        : $attendee->attendee_name)
                    : $attendee->attendee_name;

                $attendee->forceFill([
                    'attendee_name' => $nextHolderName,
                    'attendee_email' => $hasHolderEmail ? $holderEmail : $attendee->attendee_email,
                    'attendee_phone' => $hasHolderPhone ? $holderPhone : $attendee->attendee_phone,
                    'notes' => $hasNotes ? $notes : $attendee->notes,
                    'attendee_metadata' => [
                        ...$metadata,
                        'validation_notes' => $hasValidationNotes
                            ? $validationNotes
                            : data_get($metadata, 'validation_notes'),
                        'notes' => $hasNotes
                            ? $notes
                            : data_get($metadata, 'notes'),
                        'printed_ticket_synced_at' => now()->toIso8601String(),
                        'printed_ticket_synced_by_user_id' => $user->id,
                    ],
                ])->save();
            }
        });

        return response()->json([
            'message' => 'Printed ticket batch synced successfully.',
            'data' => $this->serializeOfflineSaleOrder(
                EventAttendee::with('ticket')
                    ->where('event_id', $event->id)
                    ->where('payment_method', 'manual_offline')
                    ->get()
                    ->filter(fn (EventAttendee $attendee) => data_get($attendee->attendee_metadata, 'order_id') === $orderId)
                    ->values(),
                $orderId
            ),
        ]);
    }

    public function voidOfflineSale(Request $request, int $id, string $orderId)
    {
        $user = auth()->user();
        $event = $this->findAccessibleEventForOps($user, $id);
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $orderAttendees = EventAttendee::with('ticket')
            ->where('event_id', $event->id)
            ->where('payment_method', 'manual_offline')
            ->get()
            ->filter(fn (EventAttendee $attendee) => data_get($attendee->attendee_metadata, 'order_id') === $orderId)
            ->values();

        if ($orderAttendees->isEmpty()) {
            return response()->json(['message' => 'Offline sale order not found.'], 404);
        }

        if ($orderAttendees->contains(fn (EventAttendee $attendee) => $attendee->hasAttended())) {
            return response()->json(['message' => 'Checked-in offline tickets cannot be voided from this workflow.'], 422);
        }

        \DB::transaction(function () use ($orderAttendees, $validated, $user) {
            $activeByTicket = $orderAttendees
                ->filter(fn (EventAttendee $attendee) => ! $attendee->isCancelled())
                ->groupBy('ticket_id');

            foreach ($activeByTicket as $ticketId => $group) {
                $ticket = EventTicket::query()->lockForUpdate()->find($ticketId);
                if ($ticket) {
                    $ticket->reverseSale($group->count());
                }
            }

            foreach ($orderAttendees as $attendee) {
                if (! $attendee->isCancelled()) {
                    $metadata = $attendee->attendee_metadata ?? [];
                    $attendee->forceFill([
                        'payment_status' => 'voided',
                        'attendee_metadata' => [
                            ...$metadata,
                            'offline_sale_voided' => true,
                            'offline_sale_voided_at' => now()->toIso8601String(),
                            'offline_sale_voided_by_user_id' => $user->id,
                            'offline_sale_void_reason' => trim((string) ($validated['reason'] ?? '')) ?: null,
                        ],
                    ])->save();
                    $attendee->cancel();
                }
            }
        });

        return response()->json([
            'message' => 'Offline sale voided successfully.',
        ]);
    }

    public function exportAnalytics(int $id)
    {
        $user = auth()->user();
        $event = Event::with(['attendees', 'payoutLedgerEntries'])
            ->ownedByUser($user)
            ->findOrFail($id);

        $rows = $this->eventPayoutLedgerService->exportRowsForEvent($event);
        $filename = 'artist_event_payouts_'.$event->id.'.csv';
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Tesotunes Event Payout Export']);
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
    }

    private function normalizeRequirements(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\r\n|\r|\n|,/', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item) {
            return is_string($item) ? trim($item) : null;
        }, $value)));
    }

    private function normalizeContactInfo(mixed $value): array
    {
        $decoded = $this->normalizeJsonObject($value);
        $allowed = Arr::only($decoded, [
            'support_email',
            'support_phone',
            'age_restriction',
            'door_notes',
            'tax_vat_notes',
            'invoice_issuer_name',
            'invoice_support_email',
            'tax_registration_number',
            'tax_rate_percent',
            'tax_is_inclusive',
        ]);

        if (array_key_exists('tax_rate_percent', $allowed) && $allowed['tax_rate_percent'] !== null && $allowed['tax_rate_percent'] !== '') {
            $allowed['tax_rate_percent'] = round(max(0, (float) $allowed['tax_rate_percent']), 2);
        }

        if (array_key_exists('tax_is_inclusive', $allowed)) {
            $allowed['tax_is_inclusive'] = filter_var($allowed['tax_is_inclusive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return array_filter($allowed, static fn ($item) => $item !== null && $item !== '');
    }

    private function normalizeJsonObject(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function normalizeMarketingSettings(mixed $value): array
    {
        $decoded = $this->normalizeJsonObject($value);
        $campaignSpend = data_get($decoded, 'campaign_spend', []);
        $campaignPresets = data_get($decoded, 'campaign_presets', []);

        if (! is_array($campaignSpend)) {
            $campaignSpend = [];
        }
        if (! is_array($campaignPresets)) {
            $campaignPresets = [];
        }

        $normalizedSpend = array_values(array_filter(array_map(function ($entry) {
            if (! is_array($entry)) {
                return null;
            }

            $label = trim((string) ($entry['label'] ?? $entry['source'] ?? $entry['campaign_code'] ?? ''));
            $amount = (float) ($entry['amount'] ?? 0);

            if ($label === '' && $amount <= 0) {
                return null;
            }

            return [
                'key' => trim((string) ($entry['key'] ?? Str::slug($label ?: 'campaign-spend'))),
                'label' => $label !== '' ? $label : 'Campaign Spend',
                'amount' => round(max($amount, 0), 2),
                'notes' => isset($entry['notes']) ? trim((string) $entry['notes']) : null,
                'currency' => trim((string) ($entry['currency'] ?? 'UGX')) ?: 'UGX',
            ];
        }, $campaignSpend)));

        $normalizedPresets = array_values(array_filter(array_map(function ($entry, int $index) {
            if (! is_array($entry)) {
                return null;
            }

            $name = trim((string) ($entry['name'] ?? $entry['label'] ?? ''));
            $source = trim((string) ($entry['source'] ?? ''));
            $medium = trim((string) ($entry['medium'] ?? ''));
            $campaignCode = trim((string) ($entry['campaign_code'] ?? ''));
            $channel = trim((string) ($entry['channel'] ?? $medium));
            $notes = isset($entry['notes']) ? trim((string) $entry['notes']) : null;

            if ($name === '' && $source === '' && $medium === '' && $campaignCode === '') {
                return null;
            }

            $derivedName = $name !== ''
                ? $name
                : ucwords(str_replace(['-', '_'], ' ', $campaignCode !== '' ? $campaignCode : ($source !== '' ? $source : 'Campaign Preset')));

            return [
                'key' => trim((string) ($entry['key'] ?? Str::slug($derivedName !== '' ? $derivedName : 'campaign-preset-'.($index + 1)))) ?: 'campaign-preset-'.($index + 1),
                'name' => $derivedName,
                'source' => $source !== '' ? $source : 'tesotunes_promote',
                'medium' => $medium !== '' ? $medium : 'featured_banner',
                'channel' => $channel !== '' ? $channel : ($medium !== '' ? $medium : 'featured_banner'),
                'campaign_code' => $campaignCode !== '' ? $campaignCode : Str::slug($derivedName !== '' ? $derivedName : 'campaign-preset-'.($index + 1)),
                'notes' => $notes !== '' ? $notes : null,
            ];
        }, $campaignPresets, array_keys($campaignPresets))));

        return [
            'campaign_spend' => $normalizedSpend,
            'campaign_presets' => $normalizedPresets,
        ];
    }

    private function serializePromotionRequest(EventPromotionRequest $promotionRequest): array
    {
        return [
            'id' => $promotionRequest->id,
            'uuid' => $promotionRequest->uuid,
            'event_id' => $promotionRequest->event_id,
            'promotion_slug' => $promotionRequest->promotion_slug,
            'promotion_title' => $promotionRequest->promotion_title,
            'promotion_type' => $promotionRequest->promotion_type,
            'promotion_platform' => $promotionRequest->promotion_platform,
            'price_credits' => (float) $promotionRequest->price_credits,
            'price_ugx' => (float) $promotionRequest->price_ugx,
            'status' => $promotionRequest->status,
            'request_notes' => $promotionRequest->request_notes,
            'moderation_notes' => $promotionRequest->moderation_notes,
            'featured_image_url' => $promotionRequest->featured_image_url,
            'requested_at' => $promotionRequest->requested_at?->toIso8601String(),
            'moderated_at' => $promotionRequest->moderated_at?->toIso8601String(),
            'requested_by' => $promotionRequest->relationLoaded('requestedBy') && $promotionRequest->requestedBy ? [
                'id' => $promotionRequest->requestedBy->id,
                'name' => $promotionRequest->requestedBy->name,
                'email' => $promotionRequest->requestedBy->email,
            ] : null,
            'moderated_by' => $promotionRequest->relationLoaded('moderatedBy') && $promotionRequest->moderatedBy ? [
                'id' => $promotionRequest->moderatedBy->id,
                'name' => $promotionRequest->moderatedBy->name,
                'email' => $promotionRequest->moderatedBy->email,
            ] : null,
        ];
    }

    private function buildPayoutCenter($user, Event $event): array
    {
        $user->loadMissing('artistProfile');
        $profile = $user->artistProfile;
        $summary = $this->eventPayoutLedgerService->summarizeForEvent($event);
        $bankAccount = $profile?->bank_account;

        return [
            'setup_complete' => (bool) ($profile && (
                (($profile->payout_method ?? null) === 'mobile_money' && $profile->mobile_money_number)
                || (($profile->payout_method ?? null) === 'bank_transfer' && $profile->bank_name && $bankAccount)
                || (($profile->payout_method ?? null) === 'cash')
            )),
            'money_payout_enabled' => (bool) ($profile?->money_payout_enabled ?? false),
            'minimum_payout' => (float) ($profile?->minimum_payout ?? 0),
            'verification_status' => $profile?->verification_status ?? 'pending',
            'method' => $profile?->payout_method ?? null,
            'method_label' => $profile?->payout_method_display ?? 'Not Set',
            'mobile_money_provider' => $profile?->mobile_money_provider,
            'mobile_money_number' => $profile?->mobile_money_number,
            'bank_name' => $profile?->bank_name,
            'bank_account_masked' => $bankAccount ? str_repeat('*', max(strlen($bankAccount) - 4, 0)).substr($bankAccount, -4) : null,
            'pending_balance' => $summary['pending_balance'] ?? 0,
            'ready_balance' => $summary['ready_balance'] ?? 0,
            'settled_balance' => $summary['settled_balance'] ?? 0,
            'failed_balance' => $summary['failed_balance'] ?? 0,
            'entry_count' => $summary['entry_count'] ?? 0,
            'latest_ready_at' => $summary['latest_ready_at'] ?? null,
            'latest_paid_out_at' => $summary['latest_paid_out_at'] ?? null,
        ];
    }

    private function findAccessibleEventForOps(User $user, int $eventId): Event
    {
        $event = Event::with(['staffMembers'])
            ->findOrFail($eventId);

        $isOwner = (bool) Event::query()
            ->whereKey($event->id)
            ->ownedByUser($user)
            ->exists();

        if ($isOwner) {
            return $event;
        }

        $staffMembership = $event->staffMembers
            ->first(fn (EventStaffMember $member) => $member->user_id === $user->id && in_array($member->role, [
                EventStaffMember::ROLE_FINANCE,
                EventStaffMember::ROLE_CHECK_IN,
                EventStaffMember::ROLE_ANALYST,
            ], true));

        abort_unless($staffMembership || $user->hasAnyRole(['admin', 'super_admin']), 403, 'You do not have access to this event operation.');

        return $event;
    }

    private function abortUnlessCanManageExternalAllocations(User $user, Event $event): void
    {
        $isOwner = (bool) Event::query()
            ->whereKey($event->id)
            ->ownedByUser($user)
            ->exists();

        if ($isOwner || $user->hasAnyRole(['admin', 'super_admin'])) {
            return;
        }

        $staffMembership = $event->staffMembers
            ->first(fn (EventStaffMember $member) => $member->user_id === $user->id && $member->role === EventStaffMember::ROLE_FINANCE);

        abort_unless($staffMembership, 403, 'You do not have access to manage external capacity allocations for this event.');
    }

    private function serializeCheckInAttendee(EventAttendee $attendee): array
    {
        return [
            'id' => $attendee->id,
            'ticket_number' => $attendee->confirmation_code,
            'status' => $attendee->status,
            'holder_name' => $attendee->attendee_name,
            'holder_email' => $attendee->attendee_email,
            'holder_phone' => $attendee->attendee_phone,
            'checked_in_at' => $attendee->checked_in_at?->toIso8601String(),
            'duplicate_warning' => $attendee->checked_in_at !== null,
            'notes' => $attendee->notes,
            'sales_channel' => data_get($attendee->attendee_metadata, 'sales_channel'),
            'ticket_source' => data_get($attendee->attendee_metadata, 'ticket_source'),
            'printed_ticket_import' => (bool) data_get($attendee->attendee_metadata, 'printed_ticket_import', false),
            'validation_notes' => data_get($attendee->attendee_metadata, 'validation_notes'),
            'door_notes' => data_get($attendee->attendee_metadata, 'door_notes', []),
            'ticket' => $attendee->ticket ? [
                'id' => $attendee->ticket->id,
                'name' => $attendee->ticket->name,
                'price_ugx' => (float) ($attendee->ticket->price_ugx ?? 0),
            ] : null,
            'user' => $attendee->user ? [
                'id' => $attendee->user->id,
                'name' => $attendee->user->name,
                'email' => $attendee->user->email,
            ] : null,
        ];
    }

    private function serializeTicketCase(EventTicketCase $case): array
    {
        $attendee = $case->attendee;

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
            'created_at' => $case->created_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'attendee' => $attendee ? [
                'id' => $attendee->id,
                'ticket_number' => $attendee->confirmation_code,
                'status' => $attendee->status,
                'holder_name' => $attendee->attendee_name,
                'holder_email' => $attendee->attendee_email,
                'holder_phone' => $attendee->attendee_phone,
                'price_paid' => (float) ($attendee->price_paid_ugx ?? $attendee->amount_paid ?? 0),
                'ticket_tier' => $attendee->relationLoaded('ticket') && $attendee->ticket ? [
                    'id' => $attendee->ticket->id,
                    'name' => $attendee->ticket->name,
                ] : null,
            ] : null,
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
        ];
    }

    private function serializeOfflineSaleOrder($group, string $orderId): array
    {
        $attendees = collect($group)->values();
        /** @var EventAttendee|null $first */
        $first = $attendees->first();
        $metadata = $first?->attendee_metadata ?? [];

        return [
            'order_id' => $orderId,
            'status' => $attendees->every(fn (EventAttendee $attendee) => $attendee->isCancelled()) ? 'voided' : 'active',
            'sale_source' => data_get($metadata, 'offline_sale_source') ?? data_get($metadata, 'ticket_source'),
            'notes' => data_get($metadata, 'notes') ?? $first?->notes,
            'validation_notes' => data_get($metadata, 'validation_notes'),
            'printed_ticket_import' => (bool) data_get($metadata, 'printed_ticket_import', false),
            'last_synced_at' => data_get($metadata, 'printed_ticket_synced_at'),
            'holder_name' => $first?->attendee_name,
            'holder_email' => $first?->attendee_email,
            'holder_phone' => $first?->attendee_phone,
            'logged_at' => data_get($metadata, 'logged_at') ?? $first?->created_at?->toIso8601String(),
            'quantity' => $attendees->count(),
            'checked_in_count' => $attendees->filter(fn (EventAttendee $attendee) => $attendee->checked_in_at !== null)->count(),
            'voided_count' => $attendees->filter(fn (EventAttendee $attendee) => $attendee->isCancelled())->count(),
            'unit_price_ugx' => (float) ($first?->price_paid_ugx ?? 0),
            'total_amount' => round((float) $attendees->sum(fn (EventAttendee $attendee) => $attendee->amount_paid ?? $attendee->price_paid_ugx ?? 0), 2),
            'ticket_tier' => $first && $first->relationLoaded('ticket') && $first->ticket ? [
                'id' => $first->ticket->id,
                'name' => $first->ticket->name,
            ] : null,
            'ticket_numbers' => $attendees->take(5)->map(fn (EventAttendee $attendee) => $attendee->confirmation_code)->values()->all(),
        ];
    }

    private function serializeExternalAllocation(EventTicketChannelAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'channel' => $allocation->channel,
            'channel_label' => $allocation->channel_label ?: 'External',
            'quantity' => (int) $allocation->quantity,
            'notes' => $allocation->notes,
            'status' => $allocation->released_at ? 'released' : 'active',
            'created_at' => $allocation->created_at?->toIso8601String(),
            'released_at' => $allocation->released_at?->toIso8601String(),
            'release_reason' => $allocation->release_reason,
            'ticket_tier' => $allocation->relationLoaded('ticket') && $allocation->ticket ? [
                'id' => $allocation->ticket->id,
                'name' => $allocation->ticket->name,
                'available' => $allocation->ticket->quantity_available,
            ] : null,
            'logged_by' => $allocation->relationLoaded('loggedBy') && $allocation->loggedBy ? [
                'id' => $allocation->loggedBy->id,
                'name' => $allocation->loggedBy->display_name ?? $allocation->loggedBy->name ?? $allocation->loggedBy->username,
                'email' => $allocation->loggedBy->email,
            ] : null,
            'released_by' => $allocation->relationLoaded('releasedBy') && $allocation->releasedBy ? [
                'id' => $allocation->releasedBy->id,
                'name' => $allocation->releasedBy->display_name ?? $allocation->releasedBy->name ?? $allocation->releasedBy->username,
                'email' => $allocation->releasedBy->email,
            ] : null,
        ];
    }
}
