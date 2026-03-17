<?php

use App\Models\Sacco\SaccoLoanProduct;
use App\Models\Sacco\SaccoMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated users can join sacco before membership middleware applies', function () {
    config()->set('sacco.enabled', true);

    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/sacco/membership')
        ->assertOk()
        ->assertExactJson(['data' => null]);

    $joinResponse = $this->postJson('/api/sacco/join', [
        'phone_number' => '0700000000',
    ]);

    $joinResponse->assertCreated()
        ->assertJsonPath('message', 'Welcome to TesoTunes SACCO!')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.status', 'active');

    expect(SaccoMember::where('user_id', $user->id)->exists())->toBeTrue();

    $this->getJson('/api/sacco/membership')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.status', 'active');
});

test('member lifecycle supports savings and loan flows end to end', function () {
    config()->set('sacco.enabled', true);

    $user = User::factory()->create(['ugx_balance' => 250000]);
    $member = SaccoMember::create([
        'user_id' => $user->id,
        'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        'status' => 'active',
        'joined_at' => now(),
        'joined_date' => now()->toDateString(),
        'phone_number' => '0700000001',
    ]);

    Sanctum::actingAs($user);

    $accountResponse = $this->postJson('/api/sacco/savings/accounts', [
        'member_id' => $member->id,
        'account_type' => 'regular',
        'account_name' => 'Main Savings',
    ]);

    $accountResponse->assertCreated()
        ->assertJsonPath('message', 'Savings account opened successfully.');

    $accountId = $accountResponse->json('data.id');

    $this->postJson('/api/sacco/savings/deposit', [
        'account_id' => $accountId,
        'amount' => 250000,
    ])->assertOk()
        ->assertJsonPath('data.balance_ugx', '250000.00');

    $loanResponse = $this->postJson('/api/sacco/loans/apply', [
        'member_id' => $member->id,
        'principal_amount_ugx' => 120000,
        'tenure_months' => 6,
        'purpose' => 'Working capital',
    ]);

    $loanResponse->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $loanId = $loanResponse->json('data.id');

    $this->postJson("/api/sacco/loans/{$loanId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->postJson("/api/sacco/loans/{$loanId}/disburse")
        ->assertOk()
        ->assertJsonPath('data.status', 'disbursed');

    $this->postJson("/api/sacco/loans/{$loanId}/repay", [
        'amount' => 40000,
        'payment_method' => 'manual',
    ])->assertOk()
        ->assertJsonPath('message', 'Repayment recorded successfully.');

    $this->getJson("/api/sacco/loans/{$loanId}/balance")
        ->assertOk()
        ->assertJsonPath('data.status', 'disbursed');
});

test('canonical sacco summary endpoints align with frontend expectations', function () {
    config()->set('sacco.enabled', true);

    $user = User::factory()->create(['ugx_balance' => 500000]);
    $member = SaccoMember::create([
        'user_id' => $user->id,
        'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        'status' => 'active',
        'joined_at' => now(),
        'joined_date' => now()->toDateString(),
        'phone_number' => '0700000099',
        'total_savings' => 0,
        'total_shares' => 0,
    ]);

    $product = SaccoLoanProduct::create([
        'name' => 'Creator Boost',
        'code' => 'CB-001',
        'description' => 'Short-term creator working capital',
        'loan_type' => 'development',
        'min_amount' => 50000,
        'max_amount' => 5000000,
        'interest_rate' => 12,
        'min_term_months' => 3,
        'max_term_months' => 12,
        'min_repayment_months' => 3,
        'max_repayment_months' => 12,
        'processing_fee_percentage' => 2,
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/sacco/dashboard')
        ->assertOk()
        ->assertJsonPath('data.member.member_number', $member->member_number)
        ->assertJsonPath('data.accounts.savings', 0);

    $this->postJson('/api/sacco/savings/deposit', [
        'amount' => 150000,
        'phone_number' => '0700000099',
        'payment_method' => 'mtn_momo',
    ])->assertOk()
        ->assertJsonPath('message', 'Deposit successful.');

    $this->getJson('/api/sacco/savings')
        ->assertOk()
        ->assertJsonPath('data.balance', 150000)
        ->assertJsonCount(1, 'data.accounts');

    $this->getJson('/api/sacco/transactions?limit=5')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'deposit');

    $this->getJson('/api/sacco/loan-products')
        ->assertOk()
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.name', 'Creator Boost');

    $this->getJson("/api/sacco/loans/eligibility?product_id={$product->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $product->id)
        ->assertJsonPath('data.credit_score', $member->credit_score);

    $this->postJson('/api/sacco/loans/calculate-schedule', [
        'product_id' => $product->id,
        'amount' => 120000,
        'term_months' => 6,
    ])->assertOk()
        ->assertJsonPath('data.summary.term_months', 6)
        ->assertJsonCount(6, 'data.schedule');

    $loanResponse = $this->postJson('/api/sacco/loans/apply', [
        'product_id' => $product->id,
        'amount' => 120000,
        'term_months' => 6,
        'purpose' => 'Studio session financing',
        'phone_number' => '0700000099',
        'payment_method' => 'mtn_momo',
    ]);

    $loanResponse->assertCreated()
        ->assertJsonPath('data.loan_type', 'development')
        ->assertJsonPath('data.principal_amount', '120000.00');

    $loanId = $loanResponse->json('data.id');

    $this->postJson("/api/sacco/loans/{$loanId}/approve")
        ->assertOk();

    $this->postJson("/api/sacco/loans/{$loanId}/disburse")
        ->assertOk();

    $this->postJson("/api/sacco/loans/{$loanId}/pay", [
        'amount' => 20000,
        'phone_number' => '0700000099',
        'payment_method' => 'mtn_momo',
    ])->assertOk()
        ->assertJsonPath('message', 'Repayment recorded successfully.');

    $this->postJson('/api/sacco/shares/buy', [
        'quantity' => 2,
        'phone_number' => '0700000099',
        'payment_method' => 'mtn_momo',
    ])->assertCreated();

    $this->getJson('/api/sacco/shares')
        ->assertOk()
        ->assertJsonPath('data.total_shares', 2)
        ->assertJsonPath('data.purchases.0.quantity', 2);

    $guarantorUser = User::factory()->create();
    SaccoMember::create([
        'user_id' => $guarantorUser->id,
        'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        'status' => 'active',
        'joined_at' => now()->subMonths(6),
        'joined_date' => now()->subMonths(6)->toDateString(),
        'credit_score' => 640,
        'total_savings' => 500000,
        'total_shares' => 200000,
    ]);

    $this->getJson('/api/sacco/loans/guarantors?search=')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.credit_score', 640);
});
