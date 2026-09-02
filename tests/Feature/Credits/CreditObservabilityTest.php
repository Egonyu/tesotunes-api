<?php

namespace Tests\Feature\Credits;

use App\Models\CreditIssue;
use App\Models\User;
use App\Services\Credits\CreditObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The layer exists because four award paths failed silently for four months
 * while the ledger reconciled perfectly throughout. These tests hold the line
 * that mattered: a credit that does not land leaves a record saying so.
 */
class CreditObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private function observability(): CreditObservabilityService
    {
        return app(CreditObservabilityService::class);
    }

    public function test_a_successful_award_credits_the_user_and_opens_no_issue(): void
    {
        $user = User::factory()->create();

        $transaction = $this->observability()->award($user, 100, 'listen_earn', 'Pool share');

        $this->assertNotNull($transaction);
        $this->assertSame(100.0, (float) $user->fresh()->creditWallet->balance);
        $this->assertSame(0, CreditIssue::count());
    }

    /** An account that has never held a wallet must still be reachable. */
    public function test_an_award_creates_the_wallet_when_the_account_has_none(): void
    {
        $user = User::factory()->create();
        $user->creditWallet()->delete();
        $this->assertNull($user->fresh()->creditWallet);

        $this->observability()->award($user, 350, 'tip_received', 'Tip received');

        $this->assertSame(350.0, (float) $user->fresh()->creditWallet->balance);
        $this->assertSame(0, CreditIssue::count());
    }

    public function test_a_throwing_award_opens_an_issue_instead_of_vanishing(): void
    {
        $user = User::factory()->create();

        // Stand in for whatever can fail underneath — a wallet write, a
        // deadlock, a constraint. The point is that the caller gets null *and*
        // a record, never null on its own.
        $exploding = \Mockery::mock($user)->makePartial();
        $exploding->shouldReceive('addCredits')
            ->once()
            ->andThrow(new \RuntimeException('wallet write failed'));

        $result = $this->observability()->award($exploding, 500, 'tip_received', 'Tip received');

        $this->assertNull($result);

        $issue = CreditIssue::first();
        $this->assertNotNull($issue, 'A failed award must leave an issue behind.');
        $this->assertSame(CreditIssue::TYPE_AWARD_FAILED, $issue->issue_type);
        $this->assertSame('tip_received', $issue->source);
        $this->assertSame('500.00', (string) $issue->amount);
        $this->assertFalse($issue->credits_awarded);
        $this->assertStringContainsString('wallet write failed', $issue->description);
    }

    /** Repeated failures are one debt, not a queue full of identical lines. */
    public function test_repeated_failures_accumulate_on_one_issue(): void
    {
        $user = User::factory()->create();

        $this->observability()->recordIssue($user, CreditIssue::TYPE_AWARD_FAILED, 'First', [
            'source' => 'listen_earn',
            'amount' => 40,
        ]);
        $this->observability()->recordIssue($user, CreditIssue::TYPE_AWARD_FAILED, 'Second', [
            'source' => 'listen_earn',
            'amount' => 60,
        ]);

        $this->assertSame(1, CreditIssue::count());

        $issue = CreditIssue::first();
        $this->assertSame('100.00', (string) $issue->amount, 'Two lost awards are twice the debt.');
        $this->assertSame(1, $issue->auto_resolve_attempts);
    }

    /** A resolved issue does not absorb a fresh failure. */
    public function test_a_new_failure_after_resolution_opens_a_new_issue(): void
    {
        $user = User::factory()->create();

        $first = $this->observability()->recordIssue($user, CreditIssue::TYPE_AWARD_FAILED, 'First', [
            'source' => 'tip_received',
            'amount' => 10,
        ]);
        $first->markAsResolved(CreditIssue::RESOLUTION_BACKFILLED, 'paid');

        $this->observability()->recordIssue($user, CreditIssue::TYPE_AWARD_FAILED, 'Second', [
            'source' => 'tip_received',
            'amount' => 10,
        ]);

        $this->assertSame(2, CreditIssue::count());
    }

    public function test_balance_drift_is_detected_and_raised(): void
    {
        $user = User::factory()->create();
        $this->observability()->award($user, 100, 'listen_earn', 'Pool share');

        // Balance moved without a matching transaction — the thing a ledger
        // cannot tell you about itself.
        $user->creditWallet->update(['balance' => 250]);

        $result = $this->observability()->detectBalanceDrift();

        $this->assertSame(1, $result['drifted']);

        $issue = CreditIssue::where('issue_type', CreditIssue::TYPE_BALANCE_DRIFT)->first();
        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity);
        $this->assertSame('150.00', (string) $issue->amount);
    }

    public function test_a_reconciled_wallet_raises_nothing(): void
    {
        $user = User::factory()->create();
        $this->observability()->award($user, 100, 'listen_earn', 'Pool share');

        $this->assertSame(0, $this->observability()->detectBalanceDrift()['drifted']);
        $this->assertSame(0, CreditIssue::count());
    }

    public function test_the_summary_reports_what_is_still_owed(): void
    {
        $user = User::factory()->create();

        $this->observability()->recordIssue($user, CreditIssue::TYPE_AWARD_FAILED, 'Lost tip', [
            'source' => 'tip_received',
            'amount' => 350,
            'severity' => 'critical',
        ]);

        $summary = $this->observability()->summary();

        $this->assertSame(1, $summary['open_issues']);
        $this->assertSame(1, $summary['high_severity']);
        $this->assertSame(350.0, $summary['credits_still_owed']);
        $this->assertSame(1, $summary['by_source']['tip_received']);
    }
}
