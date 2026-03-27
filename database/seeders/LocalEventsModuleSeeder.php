<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventDiscountCode;
use App\Models\EventFunnelTouchpoint;
use App\Models\EventPromotionRequest;
use App\Models\EventPayoutLedgerEntry;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\EventTicketCase;
use App\Models\EventTicketChannelAllocation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LocalEventsModuleSeeder extends Seeder
{
    private array $tableColumns = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalEventsModuleSeeder skipped outside local/testing.');

            return;
        }

        DB::transaction(function () {
            $this->ensureRoles();

            $admin = $this->upsertUser('local-admin@tesotunes.test', 'localadmin', 'Local Admin', 'admin', [
                'first_name' => 'Local',
                'last_name' => 'Admin',
                'phone' => '256700000001',
            ]);

            $organizer = $this->upsertUser('local-organizer@tesotunes.test', 'localorganizer', 'Teso Live Events', 'artist', [
                'first_name' => 'Teso',
                'last_name' => 'Organizer',
                'phone' => '256700000002',
                'is_artist' => true,
                'payment_method' => 'mobile_money',
                'mobile_money_provider' => 'mtn',
                'mobile_money_number' => '256700000002',
                'settings' => [
                    'event_organizer' => [
                        'business_name' => 'Teso Live Events',
                        'support_email' => 'organizer@tesotunes.test',
                        'support_phone' => '256700000002',
                        'payout_method' => 'mobile_money',
                        'payout_details' => [
                            'mobile_money_provider' => 'mtn',
                            'mobile_money_number' => '256700000002',
                        ],
                        'is_ready' => true,
                    ],
                ],
            ]);

            $financeUser = $this->upsertUser('local-finance@tesotunes.test', 'localfinance', 'Local Finance', 'user', [
                'first_name' => 'Local',
                'last_name' => 'Finance',
                'phone' => '256700000003',
            ]);

            $doorUser = $this->upsertUser('local-door@tesotunes.test', 'localdoor', 'Local Door Staff', 'user', [
                'first_name' => 'Local',
                'last_name' => 'Door',
                'phone' => '256700000004',
            ]);

            $buyerOne = $this->upsertUser('buyer-one@tesotunes.test', 'buyerone', 'Buyer One', 'user', [
                'first_name' => 'Buyer',
                'last_name' => 'One',
                'phone' => '256700000005',
            ]);

            $buyerTwo = $this->upsertUser('buyer-two@tesotunes.test', 'buyertwo', 'Buyer Two', 'user', [
                'first_name' => 'Buyer',
                'last_name' => 'Two',
                'phone' => '256700000006',
            ]);

            $organizerArtist = $this->upsertArtist($organizer, 'Teso Live Events', 'teso-live-events');
            $guestArtist = $this->upsertArtist($buyerTwo, 'Aroma Stage', 'aroma-stage');

            $cleanEvent = $this->upsertEvent('local-low-risk-session', 'Local Low Risk Session', $organizer, $organizerArtist, Event::TICKETING_MODE_TESOTUNES_MANAGED, now()->addDays(9)->setTime(19, 0), [
                'category' => 'concert',
                'city' => 'Soroti',
                'venue_name' => 'Soroti Social Hall',
                'venue_address' => 'Plot 14, Market Street',
                'capacity' => 250,
                'description' => 'A clean local test event with healthy funnel, payouts, and low-risk activity.',
                'contact_info' => [
                    'support_email' => 'organizer@tesotunes.test',
                    'support_phone' => '256700000002',
                    'invoice_issuer_name' => 'Teso Live Events',
                    'invoice_support_email' => 'billing@tesotunes.test',
                    'tax_registration_number' => 'TIN-LOCAL-001',
                    'tax_rate_percent' => 18,
                    'tax_is_inclusive' => false,
                ],
                'marketing_settings' => [
                    'campaign_spend' => [['source' => 'instagram_story', 'amount_ugx' => 120000]],
                    'campaign_presets' => [[
                        'name' => 'Instagram push',
                        'source' => 'instagram_story',
                        'campaign_code' => 'IGLOWRISK',
                    ]],
                ],
            ]);

            $riskEvent = $this->upsertEvent('local-risk-review-festival', 'Local Risk Review Festival', $organizer, $organizerArtist, Event::TICKETING_MODE_TESOTUNES_MANAGED, now()->addDays(16)->setTime(20, 0), [
                'category' => 'festival',
                'city' => 'Kampala',
                'venue_name' => 'Freedom Grounds',
                'venue_address' => 'Wampewo Avenue',
                'capacity' => 1200,
                'description' => 'A seeded event with disputes, printed imports, payout holds, and risk signals for admin review.',
                'contact_info' => [
                    'support_email' => 'riskdesk@tesotunes.test',
                    'support_phone' => '256700000002',
                    'invoice_issuer_name' => 'Teso Live Events',
                    'invoice_support_email' => 'billing@tesotunes.test',
                    'tax_registration_number' => 'TIN-LOCAL-001',
                    'tax_rate_percent' => 18,
                    'tax_is_inclusive' => true,
                ],
                'marketing_settings' => [
                    'campaign_spend' => [
                        ['source' => 'whatsapp_blast', 'amount_ugx' => 80000],
                        ['source' => 'creator_referral', 'amount_ugx' => 150000],
                    ],
                    'campaign_presets' => [
                        ['name' => 'WhatsApp push', 'source' => 'whatsapp_blast', 'campaign_code' => 'RISKWA'],
                        ['name' => 'Creator referral', 'source' => 'creator_referral', 'campaign_code' => 'RISKCR'],
                    ],
                ],
            ]);

            $hybridEvent = $this->upsertEvent('local-hybrid-door-sales', 'Local Hybrid Door Sales Night', $organizer, $organizerArtist, Event::TICKETING_MODE_HYBRID, now()->addDays(5)->setTime(18, 30), [
                'category' => 'party',
                'city' => 'Mbale',
                'venue_name' => 'Elgon Lounge',
                'venue_address' => 'Station Road',
                'capacity' => 400,
                'description' => 'A hybrid event with Tesotunes-managed tickets, printed batches, and external capacity reservations.',
                'contact_info' => [
                    'support_email' => 'hybrid@tesotunes.test',
                    'support_phone' => '256700000002',
                ],
            ]);

            $cleanEvent->artists()->syncWithoutDetaching([$organizerArtist->id, $guestArtist->id]);
            $riskEvent->artists()->syncWithoutDetaching([$organizerArtist->id, $guestArtist->id]);
            $hybridEvent->artists()->syncWithoutDetaching([$organizerArtist->id]);

            $this->seedStaff($cleanEvent, $organizer, $financeUser, $doorUser);
            $this->seedStaff($riskEvent, $organizer, $financeUser, $doorUser);
            $this->seedStaff($hybridEvent, $organizer, $financeUser, $doorUser);

            $cleanGeneral = $this->upsertTicket($cleanEvent, 'General', 25000, 180, 12, 1);
            $cleanVip = $this->upsertTicket($cleanEvent, 'VIP', 50000, 40, 24, 2);
            $riskGeneral = $this->upsertTicket($riskEvent, 'General', 30000, 900, 10, 1);
            $riskVip = $this->upsertTicket($riskEvent, 'VIP', 80000, 120, 20, 2);
            $hybridDoor = $this->upsertTicket($hybridEvent, 'Door Pass', 20000, 250, 10, 1);
            $hybridVip = $this->upsertTicket($hybridEvent, 'Table VIP', 120000, 40, 20, 2);

            $this->seedCleanAttendees($cleanEvent, $cleanGeneral, $cleanVip, $buyerOne, $buyerTwo);
            $this->seedRiskAttendees($riskEvent, $riskGeneral, $riskVip, $buyerOne, $buyerTwo, $doorUser);
            $this->seedHybridAttendees($hybridEvent, $hybridDoor, $hybridVip, $buyerOne, $doorUser, $organizer);

            $this->seedDiscountCodes($cleanEvent, $cleanGeneral, $riskEvent, $riskGeneral);
            $this->seedFunnel($cleanEvent, 'instagram_story', 'IGLOWRISK', 14, 6);
            $this->seedFunnel($riskEvent, 'creator_referral', 'RISKCR', 18, 4);
            $this->seedFunnel($hybridEvent, 'door_promo', 'HYB01', 10, 3);

            $this->seedPromotionRequest($riskEvent, $organizer, $admin);
            $this->seedAuditTrail($cleanEvent, $riskEvent, $hybridEvent, $admin, $organizer);
        });

        $this->command?->info('Local Events module data seeded.');
        $this->command?->line('Admin: local-admin@tesotunes.test / password');
        $this->command?->line('Organizer: local-organizer@tesotunes.test / password');
        $this->command?->line('Events: local-low-risk-session, local-risk-review-festival, local-hybrid-door-sales');
    }

    private function ensureRoles(): void
    {
        foreach (['user', 'artist', 'admin'] as $index => $name) {
            Role::firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => Str::headline($name),
                    'description' => 'Local events module role',
                    'permissions' => [],
                    'is_active' => true,
                    'priority' => 100 - $index,
                ]
            );
        }
    }

    private function upsertUser(string $email, string $username, string $displayName, string $role, array $attributes = []): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill($this->filterColumns('users', array_merge([
            'uuid' => $user->uuid ?: (string) Str::uuid(),
            'display_name' => $displayName,
            'name' => $displayName,
            'username' => $username,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'is_active' => true,
            'status' => 'active',
            'country' => 'Uganda',
            'city' => 'Kampala',
            'timezone' => 'Africa/Kampala',
            'language' => 'en',
        ], $attributes)));
        $user->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function upsertArtist(User $user, string $stageName, string $slug): Artist
    {
        return Artist::updateOrCreate(
            ['user_id' => $user->id],
            $this->filterColumns('artists', [
                'uuid' => Artist::where('user_id', $user->id)->value('uuid') ?: (string) Str::uuid(),
                'name' => $stageName,
                'stage_name' => $stageName,
                'slug' => $slug,
                'bio' => $stageName.' seeded local organizer/performer profile.',
                'avatar' => 'artists/avatars/'.$slug.'.jpg',
                'cover_image' => 'artists/covers/'.$slug.'.jpg',
                'status' => 'active',
                'is_verified' => true,
                'can_upload' => true,
                'payout_phone_number' => $user->mobile_money_number,
            ])
        );
    }

    private function upsertEvent(string $slug, string $title, User $organizer, Artist $artist, string $ticketingMode, $startsAt, array $attributes = []): Event
    {
        $event = Event::firstOrNew(['slug' => $slug]);
        $event->fill($this->filterColumns('events', array_merge([
            'organizer_id' => $organizer->id,
            'organizer_type' => User::class,
            'user_id' => $organizer->id,
            'artist_id' => $artist->id,
            'title' => $title,
            'description' => $title.' local seeded event.',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(6),
            'timezone' => 'Africa/Kampala',
            'doors_open_at' => $startsAt->copy()->subHour(),
            'status' => 'published',
            'visibility' => 'public',
            'category' => 'concert',
            'venue_name' => 'Local Test Venue',
            'venue_address' => 'Test Street',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'capacity' => 300,
            'total_tickets' => 300,
            'tickets_sold' => 0,
            'attendee_count' => 0,
            'is_free' => false,
            'ticketing_mode' => $ticketingMode,
            'ticket_price' => 25000,
            'currency' => 'UGX',
            'cover_image' => 'events/covers/'.$slug.'.jpg',
            'featured_image' => 'events/featured/'.$slug.'.jpg',
            'is_published' => true,
            'published_at' => now()->subDays(2),
            'registration_deadline' => $startsAt->copy()->subHours(2),
            'refund_policy' => 'Refunds reviewed up to 24 hours before doors open.',
            'cancellation_policy' => 'Organizer may reschedule if unavoidable.',
            'requirements' => ['Valid ticket or printed code required'],
            'contact_info' => [
                'support_email' => 'events@tesotunes.test',
                'support_phone' => '256700000002',
            ],
            'marketing_settings' => [],
        ], $attributes)));
        $event->save();

        return $event->fresh();
    }

    private function seedStaff(Event $event, User $organizer, User $financeUser, User $doorUser): void
    {
        EventStaffMember::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $financeUser->id, 'role' => EventStaffMember::ROLE_FINANCE],
            $this->filterColumns('event_staff_members', [
                'uuid' => EventStaffMember::where('event_id', $event->id)->where('user_id', $financeUser->id)->where('role', EventStaffMember::ROLE_FINANCE)->value('uuid') ?: (string) Str::uuid(),
                'invited_by_user_id' => $organizer->id,
                'notes' => 'Seeded finance contact',
                'is_active' => true,
            ])
        );

        EventStaffMember::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $doorUser->id, 'role' => EventStaffMember::ROLE_CHECK_IN],
            $this->filterColumns('event_staff_members', [
                'uuid' => EventStaffMember::where('event_id', $event->id)->where('user_id', $doorUser->id)->where('role', EventStaffMember::ROLE_CHECK_IN)->value('uuid') ?: (string) Str::uuid(),
                'invited_by_user_id' => $organizer->id,
                'notes' => 'Seeded door/check-in contact',
                'is_active' => true,
            ])
        );
    }

    private function upsertTicket(Event $event, string $name, int $priceUgx, int $quantityTotal, int $quantitySold, int $sortOrder): EventTicket
    {
        return EventTicket::updateOrCreate(
            ['event_id' => $event->id, 'name' => $name],
            $this->filterColumns('event_tickets', [
                'uuid' => EventTicket::where('event_id', $event->id)->where('name', $name)->value('uuid') ?: (string) Str::uuid(),
                'description' => $name.' ticket for '.$event->title,
                'price_ugx' => $priceUgx,
                'price_credits' => 0,
                'is_free' => false,
                'quantity_total' => $quantityTotal,
                'quantity_sold' => $quantitySold,
                'quantity_reserved' => 0,
                'min_per_order' => 1,
                'max_per_order' => 8,
                'sale_starts_at' => $event->published_at ?? now()->subWeek(),
                'sale_ends_at' => $event->starts_at?->copy()->subHour(),
                'is_active' => true,
                'sort_order' => $sortOrder,
            ])
        );
    }

    private function seedCleanAttendees(Event $event, EventTicket $generalTicket, EventTicket $vipTicket, User $buyerOne, User $buyerTwo): void
    {
        $first = $this->upsertAttendee($event->id, 'LOCAL-CLEAN-001', 'buyer-one@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $generalTicket->id,
                'user_id' => $buyerOne->id,
                'attendee_name' => 'Buyer One',
                'attendee_email' => $buyerOne->email,
                'attendee_phone' => '256700000005',
                'price_paid_ugx' => 25000,
                'amount_paid' => 26250,
                'payment_method' => EventAttendee::PAYMENT_METHOD_MTN_MOMO,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subDays(1),
                'payment_reference' => 'LOCAL-CLEAN-001',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-CLEAN-001',
                    'ticket_source' => 'tesotunes_native',
                    'attribution' => ['source_label' => 'instagram_story', 'campaign_code' => 'IGLOWRISK'],
                ],
            ])
        );

        $this->upsertAttendee($event->id, 'LOCAL-CLEAN-002', 'buyer-two@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $vipTicket->id,
                'user_id' => $buyerTwo->id,
                'attendee_name' => 'Buyer Two',
                'attendee_email' => $buyerTwo->email,
                'attendee_phone' => '256700000006',
                'price_paid_ugx' => 50000,
                'amount_paid' => 52500,
                'payment_method' => EventAttendee::PAYMENT_METHOD_AIRTEL_MONEY,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subHours(16),
                'payment_reference' => 'LOCAL-CLEAN-002',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-CLEAN-002',
                    'ticket_source' => 'tracked_promo',
                    'attribution' => ['source_label' => 'instagram_story', 'campaign_code' => 'IGLOWRISK'],
                ],
            ])
        );

        $this->upsertLedgerEntry($event, 'LOCAL-CLEAN-001', 1, 25000, 26250, 1250, 800, 450, 23750, EventPayoutLedgerEntry::STATUS_READY, false);
        $this->upsertLedgerEntry($event, 'LOCAL-CLEAN-002', 1, 50000, 52500, 2500, 1600, 900, 47500, EventPayoutLedgerEntry::STATUS_READY, false);

        if ($this->hasColumn('event_attendees', 'qr_code')) {
            $first->generateQrCode();
        }
    }

    private function seedRiskAttendees(Event $event, EventTicket $generalTicket, EventTicket $vipTicket, User $buyerOne, User $buyerTwo, User $doorUser): void
    {
        $offline = $this->upsertAttendee($event->id, 'LOCAL-RISK-PRINT-001', 'printed-buyer@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $generalTicket->id,
                'user_id' => $doorUser->id,
                'attendee_name' => 'Printed Ticket Buyer',
                'attendee_email' => 'printed-buyer@tesotunes.test',
                'attendee_phone' => '256700000010',
                'price_paid_ugx' => 30000,
                'amount_paid' => 30000,
                'payment_method' => 'cash',
                'status' => EventAttendee::STATUS_ATTENDED,
                'confirmed_at' => now()->subDays(2),
                'checked_in_at' => now()->subHours(10),
                'attended_at' => now()->subHours(10),
                'checked_in_by_user_id' => $doorUser->id,
                'payment_reference' => 'LOCAL-RISK-PRINT-001',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-RISK-PRINT-001',
                    'ticket_source' => 'manual_offline',
                    'printed_ticket_import' => true,
                    'printed_code' => 'BOOKLET-A-001',
                    'last_check_in_override' => true,
                    'validation_notes' => 'Match booklet serial before wristband issue.',
                ],
                'notes' => 'Seeded printed ticket attendee',
            ])
        );

        $this->upsertAttendee($event->id, 'LOCAL-RISK-002', 'buyer-one@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $vipTicket->id,
                'user_id' => $buyerOne->id,
                'attendee_name' => 'Buyer One',
                'attendee_email' => $buyerOne->email,
                'attendee_phone' => '256700000005',
                'price_paid_ugx' => 80000,
                'amount_paid' => 84000,
                'payment_method' => EventAttendee::PAYMENT_METHOD_CARD,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subDay(),
                'payment_reference' => 'LOCAL-RISK-002',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-RISK-002',
                    'ticket_source' => 'tracked_promo',
                    'attribution' => [
                        'source_label' => 'creator_referral',
                        'campaign_code' => 'RISKCR',
                        'promoter_code' => 'PROMO-KAMPALA',
                    ],
                ],
            ])
        );

        $dispute = $this->upsertAttendee($event->id, 'LOCAL-RISK-003', 'buyer-two@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $generalTicket->id,
                'user_id' => $buyerTwo->id,
                'attendee_name' => 'Buyer Two',
                'attendee_email' => $buyerTwo->email,
                'attendee_phone' => '256700000006',
                'price_paid_ugx' => 30000,
                'amount_paid' => 31500,
                'payment_method' => EventAttendee::PAYMENT_METHOD_MTN_MOMO,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subHours(22),
                'payment_reference' => 'LOCAL-RISK-003',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-RISK-003',
                    'ticket_source' => 'tesotunes_native',
                ],
            ])
        );

        EventTicketCase::updateOrCreate(
            ['event_attendee_id' => $dispute->id, 'case_type' => EventTicketCase::TYPE_PAYMENT_DISPUTE],
            $this->filterColumns('event_ticket_cases', [
                'uuid' => EventTicketCase::where('event_attendee_id', $dispute->id)->where('case_type', EventTicketCase::TYPE_PAYMENT_DISPUTE)->value('uuid') ?: (string) Str::uuid(),
                'event_id' => $event->id,
                'requested_by_user_id' => $buyerTwo->id,
                'status' => EventTicketCase::STATUS_OPEN,
                'escalation_status' => EventTicketCase::ESCALATION_REVIEW,
                'reason' => 'Buyer says charge was captured twice.',
                'dispute_category' => 'chargeback_review',
                'gateway_reference' => 'GW-RISK-003',
                'evidence_notes' => 'Pending finance review for mobile money callback mismatch.',
                'requested_refund_amount' => 31500,
            ])
        );

        $this->upsertLedgerEntry($event, 'LOCAL-RISK-002', 1, 80000, 84000, 4000, 2400, 1600, 76000, EventPayoutLedgerEntry::STATUS_READY, true);
        $this->upsertLedgerEntry($event, 'LOCAL-RISK-003', 1, 30000, 31500, 1500, 900, 600, 28500, EventPayoutLedgerEntry::STATUS_FAILED, false);
        $this->upsertLedgerEntry($event, 'LOCAL-RISK-PRINT-001', 1, 30000, 30000, 0, 0, 0, 30000, EventPayoutLedgerEntry::STATUS_READY, false, ['channel' => 'manual_offline']);

        if ($this->hasColumn('event_attendees', 'qr_code')) {
            $offline->generateQrCode();
        }
    }

    private function seedHybridAttendees(Event $event, EventTicket $doorTicket, EventTicket $vipTicket, User $buyerOne, User $doorUser, User $organizer): void
    {
        $this->upsertAttendee($event->id, 'LOCAL-HYBRID-001', 'buyer-one@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $doorTicket->id,
                'user_id' => $buyerOne->id,
                'attendee_name' => 'Buyer One',
                'attendee_email' => $buyerOne->email,
                'attendee_phone' => '256700000005',
                'price_paid_ugx' => 20000,
                'amount_paid' => 21000,
                'payment_method' => EventAttendee::PAYMENT_METHOD_MTN_MOMO,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subHours(8),
                'payment_reference' => 'LOCAL-HYBRID-001',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-HYBRID-001',
                    'ticket_source' => 'tesotunes_native',
                ],
            ])
        );

        $this->upsertAttendee($event->id, 'LOCAL-HYBRID-PRINT-001', 'door-buyer@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $doorTicket->id,
                'user_id' => $organizer->id,
                'attendee_name' => 'Door Buyer',
                'attendee_email' => 'door-buyer@tesotunes.test',
                'attendee_phone' => '256700000011',
                'price_paid_ugx' => 20000,
                'amount_paid' => 20000,
                'payment_method' => 'cash',
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subHours(5),
                'payment_reference' => 'LOCAL-HYBRID-PRINT-001',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-HYBRID-PRINT-001',
                    'ticket_source' => 'manual_offline',
                    'printed_ticket_import' => true,
                    'printed_code' => 'HYB-BOOK-010',
                    'validation_notes' => 'Stamp physical booklet before entry.',
                ],
            ])
        );

        EventTicketChannelAllocation::updateOrCreate(
            ['event_id' => $event->id, 'ticket_id' => $vipTicket->id, 'channel_label' => 'Partner Outlet'],
            $this->filterColumns('event_ticket_channel_allocations', [
                'uuid' => EventTicketChannelAllocation::where('event_id', $event->id)->where('ticket_id', $vipTicket->id)->where('channel_label', 'Partner Outlet')->value('uuid') ?: (string) Str::uuid(),
                'logged_by_user_id' => $organizer->id,
                'channel' => EventTicketChannelAllocation::CHANNEL_EXTERNAL,
                'quantity' => 8,
                'notes' => 'Reserved for venue outlet sale',
            ])
        );

        $this->upsertAttendee($event->id, 'LOCAL-HYBRID-COMP-001', 'comp-guest@tesotunes.test',
            $this->filterColumns('event_attendees', [
                'event_id' => $event->id,
                'ticket_id' => $vipTicket->id,
                'user_id' => $doorUser->id,
                'attendee_name' => 'Complimentary Guest',
                'attendee_email' => 'comp-guest@tesotunes.test',
                'attendee_phone' => '256700000012',
                'price_paid_ugx' => 0,
                'amount_paid' => 0,
                'payment_method' => EventAttendee::PAYMENT_METHOD_FREE,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'confirmed_at' => now()->subHours(4),
                'payment_reference' => 'LOCAL-HYBRID-COMP-001',
                'payment_status' => 'completed',
                'attendee_metadata' => [
                    'order_id' => 'LOCAL-HYBRID-COMP-001',
                    'ticket_source' => 'manual_offline',
                    'validation_notes' => 'Approved complimentary entry',
                    'issued_by' => $doorUser->id,
                ],
            ])
        );

        $this->upsertLedgerEntry($event, 'LOCAL-HYBRID-001', 1, 20000, 21000, 1000, 600, 400, 19000, EventPayoutLedgerEntry::STATUS_PENDING, false);
    }

    private function seedDiscountCodes(Event $cleanEvent, EventTicket $cleanTicket, Event $riskEvent, EventTicket $riskTicket): void
    {
        EventDiscountCode::updateOrCreate(
            ['event_id' => $cleanEvent->id, 'code' => 'LOCAL10'],
            $this->filterColumns('event_discount_codes', [
                'uuid' => EventDiscountCode::where('event_id', $cleanEvent->id)->where('code', 'LOCAL10')->value('uuid') ?: (string) Str::uuid(),
                'name' => 'Local Ten Off',
                'discount_type' => EventDiscountCode::TYPE_PERCENTAGE,
                'discount_value' => 10,
                'usage_limit' => 50,
                'usage_count' => 3,
                'min_order_amount_ugx' => 20000,
                'applies_to_ticket_ids' => [$cleanTicket->id],
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addWeek(),
                'is_active' => true,
                'metadata' => ['seeded' => true],
            ])
        );

        EventDiscountCode::updateOrCreate(
            ['event_id' => $riskEvent->id, 'code' => 'VIP5K'],
            $this->filterColumns('event_discount_codes', [
                'uuid' => EventDiscountCode::where('event_id', $riskEvent->id)->where('code', 'VIP5K')->value('uuid') ?: (string) Str::uuid(),
                'name' => 'VIP 5K Off',
                'discount_type' => EventDiscountCode::TYPE_FIXED_AMOUNT,
                'discount_value' => 5000,
                'usage_limit' => 10,
                'usage_count' => 1,
                'min_order_amount_ugx' => 50000,
                'applies_to_ticket_ids' => [$riskTicket->id],
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(10),
                'is_active' => true,
                'metadata' => ['seeded' => true],
            ])
        );
    }

    private function seedFunnel(Event $event, string $sourceLabel, string $campaignCode, int $visits, int $checkoutStarts): void
    {
        EventFunnelTouchpoint::where('event_id', $event->id)->where('source_label', $sourceLabel)->delete();

        for ($i = 1; $i <= $visits; $i++) {
            EventFunnelTouchpoint::create($this->filterColumns('event_funnel_touchpoints', [
                'event_id' => $event->id,
                'stage' => EventFunnelTouchpoint::STAGE_VISIT,
                'session_key' => $event->slug.'-visit-'.$sourceLabel.'-'.$i,
                'source_label' => $sourceLabel,
                'source' => json_encode(['source_label' => $sourceLabel, 'campaign_code' => $campaignCode]),
                'channel' => 'social',
                'campaign_code' => $campaignCode,
                'touch_date' => now()->toDateString(),
                'landing_page' => '/events/'.$event->slug,
                'occurred_at' => now()->subHours($visits - $i),
                'metadata' => ['seeded' => true],
            ]));
        }

        for ($i = 1; $i <= $checkoutStarts; $i++) {
            EventFunnelTouchpoint::create($this->filterColumns('event_funnel_touchpoints', [
                'event_id' => $event->id,
                'stage' => EventFunnelTouchpoint::STAGE_CHECKOUT_START,
                'session_key' => $event->slug.'-checkout-'.$sourceLabel.'-'.$i,
                'source_label' => $sourceLabel,
                'source' => json_encode(['source_label' => $sourceLabel, 'campaign_code' => $campaignCode]),
                'channel' => 'social',
                'campaign_code' => $campaignCode,
                'touch_date' => now()->toDateString(),
                'landing_page' => '/events/'.$event->slug.'/checkout',
                'occurred_at' => now()->subHours($checkoutStarts - $i),
                'metadata' => ['seeded' => true],
            ]));
        }
    }

    private function seedPromotionRequest(Event $event, User $organizer, User $admin): void
    {
        EventPromotionRequest::updateOrCreate(
            ['event_id' => $event->id, 'promotion_slug' => 'homepage-boost'],
            $this->filterColumns('event_promotion_requests', [
                'uuid' => EventPromotionRequest::where('event_id', $event->id)->where('promotion_slug', 'homepage-boost')->value('uuid') ?: (string) Str::uuid(),
                'requested_by_user_id' => $organizer->id,
                'moderated_by_user_id' => $admin->id,
                'promotion_title' => 'Homepage Boost',
                'promotion_type' => 'marketplace',
                'promotion_platform' => 'tesotunes',
                'price_credits' => 0,
                'price_ugx' => 200000,
                'status' => EventPromotionRequest::STATUS_ACTIVE,
                'request_notes' => 'Seeded moderation-approved event promotion request.',
                'moderation_notes' => 'Approved for local testing.',
                'requested_at' => now()->subDay(),
                'moderated_at' => now()->subHours(20),
                'payload' => ['seeded' => true],
            ])
        );
    }

    private function seedAuditTrail(Event $cleanEvent, Event $riskEvent, Event $hybridEvent, User $admin, User $organizer): void
    {
        foreach ([
            [
                'user_id' => $organizer->id,
                'action' => 'event_campaign_settings_updated',
                'auditable_type' => Event::class,
                'auditable_id' => $cleanEvent->id,
                'old_values' => ['campaign_spend' => []],
                'new_values' => ['campaign_spend' => [['source' => 'instagram_story', 'amount_ugx' => 120000]]],
            ],
            [
                'user_id' => $organizer->id,
                'action' => 'event_discount_code_saved',
                'auditable_type' => Event::class,
                'auditable_id' => $riskEvent->id,
                'old_values' => [],
                'new_values' => ['code' => 'VIP5K'],
            ],
            [
                'user_id' => $admin->id,
                'action' => 'event_payouts_held',
                'auditable_type' => Event::class,
                'auditable_id' => $riskEvent->id,
                'old_values' => ['held_balance' => 0],
                'new_values' => ['held_balance' => 76000, 'reason' => 'Seeded risk review hold'],
            ],
            [
                'user_id' => $organizer->id,
                'action' => 'event_manual_offline_logged',
                'auditable_type' => Event::class,
                'auditable_id' => $hybridEvent->id,
                'old_values' => [],
                'new_values' => ['batch_label' => 'Booklet A', 'quantity' => 2],
            ],
        ] as $entry) {
            AuditLog::updateOrCreate(
                [
                    'action' => $entry['action'],
                    'auditable_type' => $entry['auditable_type'],
                    'auditable_id' => $entry['auditable_id'],
                ],
                $this->filterColumns('audit_logs', array_merge($entry, [
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'LocalEventsModuleSeeder',
                    'url' => 'artisan://db-seed',
                ]))
            );
        }
    }

    private function upsertLedgerEntry(Event $event, string $orderId, int $ticketQuantity, float $grossRevenue, float $customerPaid, float $tesotunesFee, float $platformCommission, float $processingFee, float $organizerNet, string $status, bool $held, array $extraMetadata = []): void
    {
        $metadata = $extraMetadata;
        if ($held) {
            $metadata['hold'] = [
                'is_held' => true,
                'reason' => 'Seeded admin hold for review',
                'held_at' => now()->subHours(12)->toIso8601String(),
            ];
        }

        EventPayoutLedgerEntry::updateOrCreate(
            ['event_id' => $event->id, 'order_id' => $orderId],
            $this->filterColumns('event_payout_ledger_entries', [
                'uuid' => EventPayoutLedgerEntry::where('event_id', $event->id)->where('order_id', $orderId)->value('uuid') ?: (string) Str::uuid(),
                'organizer_id' => $event->organizer_id,
                'payment_reference' => $orderId,
                'currency' => 'UGX',
                'ticket_quantity' => $ticketQuantity,
                'gross_revenue' => $grossRevenue,
                'customer_paid_total' => $customerPaid,
                'tesotunes_fee_revenue' => $tesotunesFee,
                'platform_commission_amount' => $platformCommission,
                'processing_fee_amount' => $processingFee,
                'organizer_net_amount' => $organizerNet,
                'fee_source' => 'subscription_package',
                'payout_status' => $status,
                'attribution_label' => data_get($metadata, 'channel', 'seeded_local'),
                'attribution' => ['seeded' => true],
                'metadata' => $metadata,
                'occurred_at' => now()->subHours(18),
                'payout_ready_at' => in_array($status, [EventPayoutLedgerEntry::STATUS_READY, EventPayoutLedgerEntry::STATUS_PAID], true) ? now()->subHours(8) : null,
                'paid_out_at' => $status === EventPayoutLedgerEntry::STATUS_PAID ? now()->subHours(2) : null,
                'failed_at' => $status === EventPayoutLedgerEntry::STATUS_FAILED ? now()->subHours(3) : null,
            ])
        );
    }

    private function upsertAttendee(int $eventId, string $reference, string $email, array $attributes): EventAttendee
    {
        if ($this->hasColumn('event_attendees', 'notes') && ! array_key_exists('notes', $attributes)) {
            $attributes['notes'] = $reference;
        }

        $match = ['event_id' => $eventId];

        if ($this->hasColumn('event_attendees', 'payment_reference')) {
            $match['payment_reference'] = $reference;
        } elseif ($this->hasColumn('event_attendees', 'attendee_email')) {
            $match['attendee_email'] = $email;
        } elseif ($this->hasColumn('event_attendees', 'notes')) {
            $match['notes'] = $reference;
        } elseif ($this->hasColumn('event_attendees', 'user_id') && array_key_exists('user_id', $attributes) && $attributes['user_id']) {
            $match['user_id'] = $attributes['user_id'];
            $match['ticket_id'] = data_get($attributes, 'ticket_id');
        } else {
            $match['ticket_id'] = data_get($attributes, 'ticket_id');
        }

        $payload = $this->filterColumns('event_attendees', array_merge($attributes, [
            'updated_at' => now(),
        ]));

        $existing = DB::table('event_attendees')->where($match)->first();

        if ($existing) {
            DB::table('event_attendees')->where('id', $existing->id)->update($payload);
            $id = $existing->id;
        } else {
            $payload['created_at'] = now();
            $id = DB::table('event_attendees')->insertGetId(array_merge($match, $payload));
        }

        return EventAttendee::query()->findOrFail($id);
    }

    private function filterColumns(string $table, array $attributes): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        if (! array_key_exists($table, $this->tableColumns)) {
            $this->tableColumns[$table] = Schema::getColumnListing($table);
        }

        return array_filter(
            $attributes,
            fn ($value, $key) => in_array($key, $this->tableColumns[$table], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        if (! array_key_exists($table, $this->tableColumns)) {
            $this->tableColumns[$table] = Schema::getColumnListing($table);
        }

        return in_array($column, $this->tableColumns[$table], true);
    }
}
