<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentObservabilityControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_view_payment_observability_dashboard_and_lists(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $customer = User::factory()->create();

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $customer->id,
            'payment_type' => 'wallet_topup',
            'status' => Payment::STATUS_PROCESSING,
            'payment_reference' => 'TT-OBS-ADMIN-001',
            'transaction_reference' => 'TT-OBS-ADMIN-001',
            'provider_transaction_id' => '550e8400-e29b-41d4-a716-446655440000',
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'initiated_at' => now()->subMinutes(8),
        ]));

        PaymentIssue::create([
            'payment_id' => $payment->id,
            'issue_type' => PaymentIssue::TYPE_STUCK_PROCESSING,
            'title' => 'Stuck processing',
            'status' => PaymentIssue::STATUS_OPEN,
            'severity' => 'critical',
            'money_deducted' => true,
            'service_delivered' => false,
        ]);

        Sanctum::actingAs($admin);

        $this
            ->getJson('/api/admin/payments/observability?search=TT-OBS-ADMIN-001')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.open_issues', 1);

        Sanctum::actingAs($admin);

        $this
            ->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.latest_issue.issue_type', PaymentIssue::TYPE_STUCK_PROCESSING);

        Sanctum::actingAs($admin);

        $this
            ->getJson('/api/admin/payment-issues')
            ->assertOk()
            ->assertJsonPath('data.0.payment_id', $payment->id);

        Sanctum::actingAs($admin);

        $this
            ->getJson('/api/admin/payments/entry-points')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'wallet_topup',
                'label' => 'Wallet Top-up',
            ]);
    }
}
