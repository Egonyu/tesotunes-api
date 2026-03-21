<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventDiscountCode;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\User;
use App\Services\Events\EventPayoutLedgerService;
use App\Services\Events\EventRevenueAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ArtistEventsController extends Controller
{
    public function __construct(
        private readonly EventRevenueAnalyticsService $eventRevenueAnalyticsService,
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
    ) {}

    /**
     * GET /api/artist/events — list artist's own events
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $events = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])
            ->ownedByUser($user)
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
            'data' => new EventResource($event->load(['organizer', 'location', 'tickets', 'staffMembers.user', 'discountCodes'])),
        ], 201);
    }

    /**
     * GET /api/artist/events/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $event = Event::with(['organizer.artist', 'user.artist', 'artist.user', 'location', 'tickets', 'attendees.ticket', 'staffMembers.user', 'discountCodes'])
            ->ownedByUser($user)
            ->findOrFail($id);

        $resource = (new EventResource($event))->toArray(request());
        $resource['attendees'] = TicketResource::collection($event->attendees)->resolve();
        $resource['payout_center'] = $this->buildPayoutCenter($user, $event);

        return response()->json(['data' => $resource]);
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

        $event->update($validated);

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

        EventDiscountCode::updateOrCreate(
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
        $discountCode->delete();

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
        $event = Event::with(['tickets', 'attendees.ticket', 'interestedUsers', 'payoutLedgerEntries'])
            ->ownedByUser($user)
            ->findOrFail($id);

        return response()->json([
            'data' => $this->eventRevenueAnalyticsService->summarize($event),
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
        ]);

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

        if (! is_array($campaignSpend)) {
            $campaignSpend = [];
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

        return [
            'campaign_spend' => $normalizedSpend,
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
}
