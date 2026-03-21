<?php

namespace Tests\Feature\Api;

use App\Jobs\SendEventCancellationNotificationsJob;
use App\Jobs\SendEventReminderNotificationsJob;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventDiscountCode;
use App\Models\EventPayoutLedgerEntry;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Notifications\EventReminderNotification;
use App\Services\Payment\ZengaPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EventTicketingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_quote_uses_event_settings_defaults_when_no_organizer_package_override_exists(): void
    {
        $buyer = User::factory()->create();
        $organizer = User::factory()->create();
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Standard',
            'price_ugx' => 10000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($buyer)->postJson('/api/tickets/quote', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.base_amount', 20000)
            ->assertJsonPath('data.platform_commission_percent', 10)
            ->assertJsonPath('data.platform_commission_amount', 2000)
            ->assertJsonPath('data.processing_fee_percent', 2.9)
            ->assertJsonPath('data.processing_fee_amount', 580)
            ->assertJsonPath('data.total_fee_amount', 2580)
            ->assertJsonPath('data.total_amount', 22580)
            ->assertJsonPath('data.organizer_net_amount', 17420)
            ->assertJsonPath('data.fee_source', 'event_settings');
    }

    public function test_ticket_quote_prefers_organizer_subscription_plan_metadata_rates(): void
    {
        $buyer = User::factory()->create();
        $organizer = User::factory()->create();
        $plan = SubscriptionPlan::factory()->artist()->active()->create([
            'metadata' => [
                'event_platform_commission_percent' => 3,
                'event_processing_fee_percent' => 1.5,
            ],
        ]);
        UserSubscription::create([
            'user_id' => $organizer->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 20,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($buyer)->postJson('/api/tickets/quote', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.base_amount', 50000)
            ->assertJsonPath('data.platform_commission_percent', 3)
            ->assertJsonPath('data.processing_fee_percent', 1.5)
            ->assertJsonPath('data.total_fee_amount', 2250)
            ->assertJsonPath('data.total_amount', 52250)
            ->assertJsonPath('data.organizer_net_amount', 47750)
            ->assertJsonPath('data.fee_source', 'subscription_plan_metadata')
            ->assertJsonPath('data.organizer_plan.id', $plan->id);
    }

    public function test_discount_code_validation_and_purchase_apply_event_discount_to_quote_and_order(): void
    {
        $buyer = User::factory()->create([
            'ugx_balance' => 120000,
        ]);
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        EventDiscountCode::create([
            'event_id' => $event->id,
            'name' => 'Launch Push',
            'code' => 'LAUNCH20',
            'discount_type' => EventDiscountCode::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'usage_limit' => 10,
            'is_active' => true,
        ]);

        $validateResponse = $this->actingAs($buyer)->postJson('/api/tickets/discounts/validate', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'quantity' => 2,
                ],
            ],
            'code' => 'launch20',
        ]);

        $validateResponse->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.code', 'LAUNCH20')
            ->assertJsonPath('data.discount_amount', 8000)
            ->assertJsonPath('data.quote.discounted_base_amount', 32000);

        $purchaseResponse = $this->actingAs($buyer)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'quantity' => 2,
                ],
            ],
            'discount_code' => 'LAUNCH20',
            'payment_method' => 'wallet',
            'holder_name' => 'Discount Buyer',
            'holder_email' => $buyer->email,
        ]);

        $purchaseResponse->assertCreated()
            ->assertJsonPath('data.fee_breakdown.discount_amount', 8000)
            ->assertJsonPath('data.fee_breakdown.discounted_base_amount', 32000)
            ->assertJsonPath('data.total_amount', 36128);

        $this->assertDatabaseHas('event_discount_codes', [
            'event_id' => $event->id,
            'code' => 'LAUNCH20',
            'usage_count' => 1,
        ]);

        $payment = Payment::query()->where('user_id', $buyer->id)->latest('id')->first();
        $this->assertSame('LAUNCH20', data_get($payment?->metadata, 'discount_code.code'));
    }

    public function test_wallet_ticket_purchase_creates_order_level_payment_record(): void
    {
        $user = User::factory()->create([
            'ugx_balance' => 120000,
        ]);

        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 20,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
            'payment_method' => 'wallet',
            'holder_name' => 'Workflow Buyer',
            'holder_email' => $user->email,
            'attribution' => [
                'source' => 'tesotunes_promote',
                'campaign_code' => 'VIP-LAUNCH',
                'utm_source' => 'instagram',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'wallet')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.base_amount', 50000)
            ->assertJsonPath('data.fee_breakdown.platform_commission_percent', 10)
            ->assertJsonPath('data.fee_breakdown.processing_fee_percent', 2.9)
            ->assertJsonPath('data.service_fee', 6450)
            ->assertJsonPath('data.total_amount', 56450);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'payment_type' => 'ticket_purchase',
            'payment_method' => 'wallet',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $paymentReference = Payment::query()->where('user_id', $user->id)->latest('id')->value('payment_reference');
        $payment = Payment::query()->where('user_id', $user->id)->latest('id')->first();

        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'payment_reference' => $paymentReference,
            'payment_status' => 'completed',
        ]);
        $this->assertSame('VIP-LAUNCH', data_get($payment?->metadata, 'attribution.campaign_code'));
        $this->assertDatabaseHas('event_payout_ledger_entries', [
            'payment_id' => $payment?->id,
            'event_id' => $event->id,
            'payout_status' => EventPayoutLedgerEntry::STATUS_READY,
            'organizer_id' => $event->organizer_id,
        ]);
    }

    public function test_multi_tier_quote_and_wallet_purchase_create_one_order_for_multiple_ticket_tiers(): void
    {
        $user = User::factory()->create([
            'ugx_balance' => 250000,
        ]);

        $event = Event::factory()->published()->create();
        $vipTicket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 50000,
            'price_credits' => 0,
            'quantity_total' => 20,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);
        $regularTicket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Regular',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 6,
            'is_active' => true,
        ]);

        $quoteResponse = $this->actingAs($user)->postJson('/api/tickets/quote', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $vipTicket->id,
                    'quantity' => 1,
                ],
                [
                    'ticket_tier_id' => $regularTicket->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $quoteResponse->assertOk()
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.base_amount', 90000)
            ->assertJsonPath('data.items.0.ticket_tier_name', 'VIP')
            ->assertJsonPath('data.items.1.ticket_tier_name', 'Regular')
            ->assertJsonPath('data.items.1.quantity', 2);

        $purchaseResponse = $this->actingAs($user)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $vipTicket->id,
                    'quantity' => 1,
                ],
                [
                    'ticket_tier_id' => $regularTicket->id,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'wallet',
            'holder_name' => 'Multi Tier Buyer',
            'holder_email' => $user->email,
        ]);

        $purchaseResponse->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.base_amount', 90000)
            ->assertJsonPath('data.line_items.0.ticket_tier_name', 'VIP')
            ->assertJsonPath('data.line_items.1.ticket_tier_name', 'Regular')
            ->assertJsonCount(3, 'data.tickets');

        $payment = Payment::query()->where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($payment);
        $this->assertSame([$vipTicket->id, $regularTicket->id], data_get($payment->metadata, 'ticket_ids'));
        $this->assertSame(3, data_get($payment->metadata, 'quantity'));

        $orderId = $purchaseResponse->json('data.order_id');
        $this->assertSame(3, EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('payment_reference', $payment->payment_reference)
            ->count());
        $this->assertSame(3, EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_metadata->order_id', $orderId)
            ->count());

        $this->assertDatabaseHas('event_tickets', [
            'id' => $vipTicket->id,
            'quantity_sold' => 1,
        ]);
        $this->assertDatabaseHas('event_tickets', [
            'id' => $regularTicket->id,
            'quantity_sold' => 2,
        ]);
    }

    public function test_guest_checkout_can_quote_and_purchase_with_mobile_money(): void
    {
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Guest Entry',
            'price_ugx' => 18000,
            'price_credits' => 0,
            'quantity_total' => 25,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $quoteResponse = $this->postJson('/api/tickets/quote', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $quoteResponse->assertOk()
            ->assertJsonPath('data.base_amount', 36000)
            ->assertJsonPath('data.total_amount', 40644);

        $purchaseResponse = $this->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'mtn_momo',
            'phone' => '0771234567',
            'holder_name' => 'Guest Buyer',
            'holder_email' => 'guest.buyer@example.com',
            'holder_phone' => '0771234567',
        ]);

        $purchaseResponse->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_method', 'mtn_momo')
            ->assertJsonPath('data.tickets.0.holder_email', 'guest.buyer@example.com');

        $payment = Payment::query()->latest('id')->first();

        $this->assertNotNull($payment);
        $this->assertSame('guest.buyer@example.com', $payment->email);
        $this->assertTrue((bool) data_get($payment->metadata, 'guest_checkout.enabled'));
        $this->assertSame('guest.buyer@example.com', data_get($payment->metadata, 'guest_checkout.contact.email'));

        $guestPurchaser = User::query()->find($payment->user_id);
        $this->assertNotNull($guestPurchaser);
        $this->assertStringStartsWith('guest+', $guestPurchaser->email);

        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'user_id' => $guestPurchaser->id,
            'attendee_email' => 'guest.buyer@example.com',
            'payment_status' => 'pending',
        ]);
    }

    public function test_ticket_purchase_supports_per_ticket_attendee_assignment_and_saved_profiles_endpoint(): void
    {
        $user = User::factory()->create([
            'ugx_balance' => 150000,
        ]);

        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Guest Pass',
            'price_ugx' => 15000,
            'price_credits' => 0,
            'quantity_total' => 20,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'wallet',
            'attendee_assignments' => [
                [
                    'ticket_tier_id' => $ticket->id,
                    'attendees' => [
                        [
                            'name' => 'Guest One',
                            'email' => 'guest.one@example.com',
                            'phone' => '0700001001',
                            'save_profile' => true,
                        ],
                        [
                            'name' => 'Guest Two',
                            'email' => 'guest.two@example.com',
                            'phone' => '0700001002',
                            'save_profile' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tickets.0.holder_name', 'Guest One')
            ->assertJsonPath('data.tickets.0.holder_email', 'guest.one@example.com')
            ->assertJsonPath('data.tickets.1.holder_name', 'Guest Two')
            ->assertJsonPath('data.tickets.1.holder_email', 'guest.two@example.com');

        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'attendee_name' => 'Guest One',
            'attendee_email' => 'guest.one@example.com',
        ]);
        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'attendee_name' => 'Guest Two',
            'attendee_email' => 'guest.two@example.com',
        ]);

        $profilesResponse = $this->actingAs($user)->getJson('/api/tickets/attendee-profiles');

        $profilesResponse->assertOk()
            ->assertJsonFragment(['name' => 'Guest One'])
            ->assertJsonFragment(['name' => 'Guest Two']);
    }

    public function test_cancelling_event_dispatches_cancellation_notification_job(): void
    {
        Bus::fake();

        $event = Event::factory()->published()->create();
        $event->cancel('Weather issues');

        Bus::assertDispatched(SendEventCancellationNotificationsJob::class, function ($job) use ($event) {
            return $job->eventId === $event->id && $job->reason === 'Weather issues';
        });
    }

    public function test_reminder_job_notifies_confirmed_attendees_for_matching_window(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->published()->create([
            'starts_at' => now()->addHours(24)->startOfHour()->addMinutes(15),
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-REM-001',
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'payment_status' => 'completed',
        ]);

        $job = new SendEventReminderNotificationsJob(24);
        $job->handle(app(\App\Services\Events\EventNotificationService::class));

        Notification::assertSentTo($user, EventReminderNotification::class);
    }

    public function test_zengapay_webhook_confirms_pending_event_order_and_settles_inventory(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General',
            'price_ugx' => 18000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'quantity_reserved' => 2,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $payment = new Payment([
            'user_id' => $user->id,
            'payable_type' => Event::class,
            'payable_id' => $event->id,
            'payment_type' => 'ticket_purchase',
            'payment_method' => 'mtn_momo',
            'provider' => 'zengapay',
            'payment_provider' => 'zengapay',
            'payment_reference' => 'PAY-WEBHOOK-001',
            'transaction_reference' => 'PAY-WEBHOOK-001',
            'metadata' => [
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'quantity' => 2,
            ],
        ]);
        $payment->forceFill([
            'amount' => 37800,
            'status' => Payment::STATUS_PROCESSING,
            'initiated_at' => now(),
        ])->save();

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'PEND-001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'attendee_name' => 'Webhook Buyer',
            'attendee_email' => $user->email,
            'payment_reference' => 'PAY-WEBHOOK-001',
            'status' => EventAttendee::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'PEND-002',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'attendee_name' => 'Webhook Buyer',
            'attendee_email' => $user->email,
            'payment_reference' => 'PAY-WEBHOOK-001',
            'status' => EventAttendee::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);

        $result = app(ZengaPayService::class)->handleWebhook([
            'transaction_id' => 'ZG-TXN-001',
            'external_reference' => 'PAY-WEBHOOK-001',
            'status' => 'successful',
        ]);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_COMPLETED,
            'provider_transaction_id' => 'ZG-TXN-001',
        ]);
        $this->assertDatabaseHas('event_tickets', [
            'id' => $ticket->id,
            'quantity_sold' => 2,
            'quantity_reserved' => 0,
        ]);
        $this->assertDatabaseHas('event_attendees', [
            'confirmation_code' => 'PEND-001',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
        ]);
        $this->assertDatabaseHas('event_payout_ledger_entries', [
            'payment_id' => $payment->id,
            'event_id' => $event->id,
            'payout_status' => EventPayoutLedgerEntry::STATUS_READY,
        ]);
    }

    public function test_failed_ticket_purchase_payment_releases_reservations_and_cancels_pending_attendees(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General',
            'price_ugx' => 18000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'quantity_reserved' => 2,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $payment = new Payment([
            'user_id' => $user->id,
            'payable_type' => Event::class,
            'payable_id' => $event->id,
            'payment_type' => 'ticket_purchase',
            'payment_method' => 'airtel_money',
            'provider' => 'zengapay',
            'payment_provider' => 'zengapay',
            'payment_reference' => 'PAY-FAIL-001',
            'transaction_reference' => 'PAY-FAIL-001',
            'metadata' => [
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'quantity' => 2,
            ],
        ]);
        $payment->forceFill([
            'amount' => 37800,
            'status' => Payment::STATUS_PROCESSING,
            'initiated_at' => now(),
        ])->save();

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'FAIL-001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'payment_reference' => 'PAY-FAIL-001',
            'status' => EventAttendee::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'FAIL-002',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'payment_reference' => 'PAY-FAIL-001',
            'status' => EventAttendee::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);

        $payment->markAsFailed('Customer declined payment');

        $this->assertDatabaseHas('event_tickets', [
            'id' => $ticket->id,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
        ]);
        $this->assertDatabaseHas('event_attendees', [
            'confirmation_code' => 'FAIL-001',
            'status' => EventAttendee::STATUS_CANCELLED,
            'payment_status' => 'failed',
        ]);
        $this->assertDatabaseHas('event_payout_ledger_entries', [
            'payment_id' => $payment->id,
            'event_id' => $event->id,
            'payout_status' => EventPayoutLedgerEntry::STATUS_FAILED,
        ]);
    }
}
