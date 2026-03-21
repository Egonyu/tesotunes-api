<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminPaymentNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentSuccessNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentObserverStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_high_value_payment_creates_custom_notifications_and_audit_logs(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $admin = User::factory()->create();
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $payment = new Payment([
            'user_id' => $user->id,
            'payment_type' => 'wallet_topup',
            'payment_method' => 'wallet',
            'currency' => 'UGX',
            'payment_reference' => 'PAY-OBS-HIGH-001',
            'transaction_reference' => 'PAY-OBS-HIGH-001',
        ]);
        $payment->forceFill([
            'amount' => 650000,
            'status' => Payment::STATUS_PENDING,
            'transaction_id' => 'TXN-OBS-HIGH-001',
        ])->save();

        $payment->markAsCompleted([
            'provider_reference' => 'PAY-OBS-HIGH-001',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'payment_completed',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'payment_success',
            'category' => 'payments',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'admin_payment_high_value',
            'category' => 'admin',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        Notification::assertSentTo($user, PaymentSuccessNotification::class);
        Notification::assertSentTo($admin, AdminPaymentNotification::class);
    }

    public function test_failed_payment_creates_custom_notification_and_audit_log_without_admins(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $payment = new Payment([
            'user_id' => $user->id,
            'payment_type' => 'wallet_topup',
            'payment_method' => 'mtn_momo',
            'currency' => 'UGX',
            'payment_reference' => 'PAY-OBS-FAIL-001',
            'transaction_reference' => 'PAY-OBS-FAIL-001',
        ]);
        $payment->forceFill([
            'amount' => 120000,
            'status' => Payment::STATUS_PENDING,
            'transaction_id' => 'TXN-OBS-FAIL-001',
        ])->save();

        $payment->markAsFailed('Customer declined payment');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'payment_failed',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'payment_failed',
            'category' => 'payments',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        Notification::assertSentTo($user, PaymentFailedNotification::class);
    }
}
