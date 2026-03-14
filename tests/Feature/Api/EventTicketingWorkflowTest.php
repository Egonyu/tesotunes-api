<?php

namespace Tests\Feature\Api;

use App\Jobs\SendEventCancellationNotificationsJob;
use App\Jobs\SendEventReminderNotificationsJob;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Services\Payment\ZengaPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EventTicketingWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'wallet')
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'payment_type' => 'ticket_purchase',
            'payment_method' => 'wallet',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $paymentReference = Payment::query()->where('user_id', $user->id)->latest('id')->value('payment_reference');

        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'payment_reference' => $paymentReference,
            'payment_status' => 'completed',
        ]);
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
    }
}
