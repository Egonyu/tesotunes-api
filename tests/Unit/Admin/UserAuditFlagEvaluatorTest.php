<?php

namespace Tests\Unit\Admin;

use App\Services\Admin\UserAudit\UserAuditFlagEvaluator;
use PHPUnit\Framework\TestCase;

class UserAuditFlagEvaluatorTest extends TestCase
{
    private function evaluator(): UserAuditFlagEvaluator
    {
        return new UserAuditFlagEvaluator(require __DIR__.'/../../../config/user_audit.php');
    }

    private function codes(array $data): array
    {
        return array_column($this->evaluator()->evaluate($data)['flags'], 'code');
    }

    private function cleanUser(): array
    {
        return [
            'identity' => ['two_factor_enabled' => true],
            'money' => ['earned_total' => 500, 'cashout_total' => 0, 'cashout_ratio' => 0.0, 'withdrawals_completed_total' => 0, 'withdrawals_failed_count' => 0, 'payout_numbers' => []],
            'kyc' => ['status' => 'verified', 'has_identity_record' => true, 'minutes_to_approval' => 240.0, 'first_withdrawal_minutes_after_verified' => null, 'documents' => []],
            'contributions' => ['available' => false],
            'listening' => ['listen_earn' => []],
            'network' => ['referees' => []],
            'security' => ['blocked_count' => 0, 'rate_limited_count' => 0, 'blocked_routes' => []],
        ];
    }

    public function test_clean_user_has_no_flags_and_zero_score(): void
    {
        $result = $this->evaluator()->evaluate($this->cleanUser());

        $this->assertSame([], $result['flags']);
        $this->assertSame(0, $result['risk_score']);
        $this->assertSame('low', $result['risk_level']);
    }

    public function test_instant_kyc_without_id_number_is_flagged_high(): void
    {
        $data = $this->cleanUser();
        $data['kyc'] = ['status' => 'verified', 'has_identity_record' => false, 'minutes_to_approval' => 2.8, 'first_withdrawal_minutes_after_verified' => 2.6, 'documents' => []];

        $codes = $this->codes($data);

        $this->assertContains('kyc_fast_approval', $codes);
        $this->assertContains('kyc_no_identity_record', $codes);
        $this->assertContains('withdrawal_soon_after_kyc', $codes);
    }

    public function test_shared_payout_number_is_critical(): void
    {
        $data = $this->cleanUser();
        $data['money']['payout_numbers'] = [['phone' => '2567•••••877', 'matches_profile' => true, 'is_completed' => true, 'other_accounts' => 2]];

        $flags = $this->evaluator()->evaluate($data)['flags'];

        $this->assertSame('payout_number_shared', $flags[0]['code']);
        $this->assertSame('critical', $flags[0]['severity']);
    }

    public function test_payout_number_matching_profile_is_not_flagged(): void
    {
        $data = $this->cleanUser();
        $data['money']['payout_numbers'] = [['phone' => 'x', 'matches_profile' => true, 'is_completed' => true, 'other_accounts' => 0]];

        $this->assertNotContains('payout_number_mismatch', $this->codes($data));
    }

    public function test_listen_cap_needs_several_low_effort_days(): void
    {
        $data = $this->cleanUser();
        $data['listening']['listen_earn'] = [
            ['amount' => 1000.0, 'seconds' => 118],
            ['amount' => 1000.0, 'seconds' => 135],
        ];
        $this->assertNotContains('listen_cap_low_effort', $this->codes($data));

        $data['listening']['listen_earn'][] = ['amount' => 1000.0, 'seconds' => 200];
        $this->assertContains('listen_cap_low_effort', $this->codes($data));
    }

    public function test_cash_out_only_ignores_tiny_earners(): void
    {
        $data = $this->cleanUser();
        $data['money'] = [...$data['money'], 'earned_total' => 400, 'cashout_total' => 400, 'cashout_ratio' => 1.0];

        $this->assertNotContains('cash_out_only', $this->codes($data));
    }

    public function test_score_keeps_rising_and_never_reaches_a_flat_hundred(): void
    {
        $few = $this->cleanUser();
        $few['security'] = ['blocked_count' => 50, 'rate_limited_count' => 0, 'blocked_routes' => []];

        $many = $this->cleanUser();
        $many['kyc'] = ['status' => 'verified', 'has_identity_record' => false, 'minutes_to_approval' => 1.0, 'first_withdrawal_minutes_after_verified' => 1.0, 'documents' => []];
        $many['security'] = ['blocked_count' => 50, 'rate_limited_count' => 50, 'blocked_routes' => []];
        $many['money']['withdrawals_failed_count'] = 5;
        $many['identity']['two_factor_enabled'] = false;
        $many['money']['withdrawals_completed_total'] = 1000.0;

        $fewScore = $this->evaluator()->evaluate($few)['risk_score'];
        $manyScore = $this->evaluator()->evaluate($many)['risk_score'];

        $this->assertGreaterThan($fewScore, $manyScore);
        $this->assertLessThan(100, $manyScore);
    }
}
