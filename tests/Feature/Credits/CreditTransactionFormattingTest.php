<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money leaving the wallet must not read as money arriving.
 *
 * getFormattedAmountAttribute tested `type === 'spend'`, a spelling nothing
 * has ever written — every row uses 'spent'. So every spend rendered
 * "+1,000 credits": a conversion out of the wallet shown as a credit in. The
 * synonym has since been retired, which is what these tests hold in place.
 */
class CreditTransactionFormattingTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(User $user, string $type, float $amount): CreditTransaction
    {
        return CreditTransaction::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => 0,
            'source' => 'test',
            'description' => 'Test movement',
        ]);
    }

    public function test_a_spend_is_signed_negative(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            '-1,000 credits',
            $this->transaction($user, CreditTransaction::TYPE_SPENT, 1000)->formatted_amount,
        );
    }

    /**
     * The retired synonym must not quietly come back as a credit.
     *
     * 'spend' is no longer a constant, but the column is a plain string, so a
     * stray row could still carry it. If one ever does it must not read as
     * money arriving — the bug this whole change exists to close.
     */
    public function test_a_stray_legacy_spend_row_is_not_shown_as_a_credit(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            '-500 credits',
            $this->transaction($user, 'spend', -500)->formatted_amount,
        );
    }

    public function test_an_earning_is_signed_positive(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            '+1,000 credits',
            $this->transaction($user, CreditTransaction::TYPE_EARNED, 1000)->formatted_amount,
        );
    }

    /** A negative amount is outgoing whatever its type says. */
    public function test_a_negative_amount_is_signed_negative(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            '-300 credits',
            $this->transaction($user, CreditTransaction::TYPE_EARNED, -300)->formatted_amount,
        );
    }

    /**
     * The dashboard's recent list and /credits/transactions describe the same
     * rows and must not disagree about their shape — the client keys off the id.
     */
    public function test_recent_transactions_carry_an_id(): void
    {
        $user = User::factory()->create();
        $user->addCredits(100, 'daily_login', 'Daily login bonus');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/credits/dashboard')
            ->assertOk();

        $recent = $response->json('data.wallet.recent_transactions');

        $this->assertNotEmpty($recent);
        $this->assertArrayHasKey('id', $recent[0]);
        $this->assertNotNull($recent[0]['id']);
    }
}
