<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Models\Sacco\SaccoShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSaccoContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->admin->id,
            'role_id' => $role->id,
            'is_active' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        cache()->forget("user:{$this->admin->id}:roles");
    }

    public function test_admin_can_fetch_member_detail_transactions_and_loans(): void
    {
        $user = User::factory()->create([
            'username' => 'member-one',
            'email' => 'member@example.com',
            'phone' => '0700001000',
        ]);

        $member = SaccoMember::create([
            'user_id' => $user->id,
            'member_number' => 'MBR-1001',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subMonths(3),
            'joined_date' => now()->subMonths(3)->toDateString(),
            'phone_number' => '0700001000',
            'credit_score' => 680,
        ]);

        $account = SaccoSavingsAccount::create([
            'member_id' => $member->id,
            'account_number' => 'SAV-1001',
            'account_name' => 'Main Savings',
            'account_type' => 'regular',
            'balance_ugx' => 250000,
            'status' => 'active',
        ]);

        SaccoSavingsTransaction::create([
            'account_id' => $account->id,
            'member_id' => $member->id,
            'type' => 'deposit',
            'amount_ugx' => 150000,
            'balance_before_ugx' => 100000,
            'balance_after_ugx' => 250000,
            'description' => 'Monthly contribution',
            'reference_number' => 'DEP-1001',
            'status' => 'completed',
            'transaction_date' => now()->subDay(),
        ]);

        SaccoShare::create([
            'member_id' => $member->id,
            'total_shares' => 4,
            'share_value_ugx' => 50000,
            'total_value_ugx' => 200000,
        ]);

        $loan = SaccoLoan::create([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'loan_number' => 'LOAN-1001',
            'principal_amount_ugx' => 300000,
            'interest_rate' => 12,
            'tenure_months' => 6,
            'purpose' => 'Equipment financing',
            'status' => SaccoLoan::STATUS_ACTIVE,
            'disbursement_date' => now()->subWeeks(2),
            'due_date' => now()->addMonths(5),
        ]);

        SaccoLoanRepayment::create([
            'loan_id' => $loan->id,
            'member_id' => $member->id,
            'amount_ugx' => 50000,
            'amount_due' => 50000,
            'amount_paid' => 50000,
            'payment_date' => now()->subWeek(),
            'status' => 'completed',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/sacco/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member_number', 'MBR-1001')
            ->assertJsonPath('data.name', 'member-one')
            ->assertJsonPath('data.savings.balance', 250000)
            ->assertJsonPath('data.shares.count', 4)
            ->assertJsonPath('data.loans.total', 1);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/sacco/members/{$member->id}/transactions")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.type', 'deposit')
            ->assertJsonPath('data.0.amount', 150000);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/sacco/members/{$member->id}/loans")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.loan_number', 'LOAN-1001')
            ->assertJsonPath('data.0.status', SaccoLoan::STATUS_ACTIVE);
    }

    public function test_admin_loan_detail_exposes_normalized_member_fields(): void
    {
        $user = User::factory()->create([
            'username' => 'loan-member',
            'email' => 'loan@example.com',
            'phone' => '0700002000',
        ]);

        $member = SaccoMember::create([
            'user_id' => $user->id,
            'member_number' => 'MBR-2001',
            'status' => SaccoMember::STATUS_ACTIVE,
            'joined_at' => now()->subMonths(2),
            'joined_date' => now()->subMonths(2)->toDateString(),
            'phone_number' => '0700002000',
        ]);

        SaccoSavingsAccount::create([
            'member_id' => $member->id,
            'account_number' => 'SAV-2001',
            'account_name' => 'Savings Wallet',
            'account_type' => 'regular',
            'balance_ugx' => 100000,
            'status' => 'active',
        ]);

        SaccoShare::create([
            'member_id' => $member->id,
            'total_shares' => 2,
            'share_value_ugx' => 50000,
            'total_value_ugx' => 100000,
        ]);

        $loan = SaccoLoan::create([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'loan_number' => 'LOAN-2001',
            'principal_amount_ugx' => 120000,
            'interest_rate' => 10,
            'tenure_months' => 4,
            'purpose' => 'Bridge financing',
            'status' => SaccoLoan::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/sacco/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.member_number', 'MBR-2001')
            ->assertJsonPath('data.member.user.name', 'loan-member')
            ->assertJsonPath('data.member.savings_balance', 100000)
            ->assertJsonPath('data.member.shares_count', 2)
            ->assertJsonPath('data.term_months', 4);
    }
}
