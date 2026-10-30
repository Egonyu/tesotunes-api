<?php

namespace App\Services\Admin\UserAudit;

/**
 * Turns the gathered audit sections into advisory flags and a 0-100 risk score.
 *
 * Pure: it reads only the array it is given, so every rule can be tested without a
 * database. Flags are advisory; nothing here blocks or changes an account.
 */
class UserAuditFlagEvaluator
{
    private readonly array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('config') ? config('user_audit', []) : []);
    }

    public function evaluate(array $data): array
    {
        $flags = collect([
            $this->kycFastApproval($data),
            $this->kycNoIdentityRecord($data),
            $this->withdrawalSoonAfterKyc($data),
            $this->payoutNumberMismatch($data),
            $this->payoutNumberShared($data),
            $this->cashOutOnly($data),
            $this->listenCapLowEffort($data),
            $this->inactiveReferees($data),
            $this->blockedRequests($data),
            $this->rateLimited($data),
            $this->failedWithdrawals($data),
            $this->noGoldTest($data),
            $this->noTwoFactorWithPayouts($data),
        ])->filter()->values();

        // Soft saturation: more flags always raise the score, but never flatten at 100.
        $raw = (int) $flags->sum(fn (array $f) => $this->weight($f['severity']));
        $score = (int) round(100 * (1 - exp(-$raw / 80)));

        return [
            'risk_score' => $score,
            'risk_level' => $this->level($score),
            'flags' => $flags->sortByDesc(fn (array $f) => $this->weight($f['severity']))->values()->all(),
        ];
    }

    private function t(string $key): float|int
    {
        return $this->config['thresholds'][$key] ?? 0;
    }

    private function weight(string $severity): int
    {
        return (int) ($this->config['weights'][$severity] ?? 0);
    }

    private function level(int $score): string
    {
        $levels = $this->config['levels'] ?? ['medium' => 20, 'high' => 50];

        return $score >= $levels['high'] ? 'high' : ($score >= $levels['medium'] ? 'medium' : 'low');
    }

    private function flag(string $code, string $severity, string $title, string $detail, array $evidence = []): array
    {
        return compact('code', 'severity', 'title', 'detail', 'evidence');
    }

    private function kycFastApproval(array $d): ?array
    {
        $minutes = $d['kyc']['minutes_to_approval'] ?? null;

        if ($minutes === null || $minutes >= $this->t('kyc_fast_approval_minutes')) {
            return null;
        }

        return $this->flag('kyc_fast_approval', 'high', 'KYC approved almost instantly',
            "Identity documents were approved {$minutes} minutes after submission.",
            ['minutes_to_approval' => $minutes, 'verified_by' => collect($d['kyc']['documents'] ?? [])->pluck('verified_by_name')->filter()->unique()->values()->all()]);
    }

    private function kycNoIdentityRecord(array $d): ?array
    {
        $kyc = $d['kyc'] ?? [];

        if (($kyc['status'] ?? null) !== 'verified' || ($kyc['has_identity_record'] ?? true)) {
            return null;
        }

        return $this->flag('kyc_no_identity_record', 'high', 'KYC verified with no ID number on record',
            'The account is marked verified but no national ID number is stored, so the approval cannot be re-checked.');
    }

    private function withdrawalSoonAfterKyc(array $d): ?array
    {
        $minutes = $d['kyc']['first_withdrawal_minutes_after_verified'] ?? null;

        if ($minutes === null || $minutes > $this->t('withdrawal_after_kyc_minutes')) {
            return null;
        }

        return $this->flag('withdrawal_soon_after_kyc', 'medium', 'First withdrawal right after KYC approval',
            "The first withdrawal was created {$minutes} minutes after KYC was approved.",
            ['minutes' => $minutes]);
    }

    private function payoutNumberMismatch(array $d): ?array
    {
        $numbers = collect($d['money']['payout_numbers'] ?? [])->where('matches_profile', false)->where('is_completed', true);

        if ($numbers->isEmpty()) {
            return null;
        }

        return $this->flag('payout_number_mismatch', 'medium', 'Payout number differs from profile phone',
            'Money was sent to a mobile money number that is not the phone on the profile, so the account holder cannot be tied to the recipient.',
            ['numbers' => $numbers->pluck('phone')->values()->all()]);
    }

    private function payoutNumberShared(array $d): ?array
    {
        $numbers = collect($d['money']['payout_numbers'] ?? [])->where('other_accounts', '>', 0);

        if ($numbers->isEmpty()) {
            return null;
        }

        return $this->flag('payout_number_shared', 'critical', 'Payout number shared with other accounts',
            'The same mobile money number has received withdrawals from more than one account.',
            ['numbers' => $numbers->map(fn ($n) => ['phone' => $n['phone'], 'other_accounts' => $n['other_accounts']])->values()->all()]);
    }

    private function cashOutOnly(array $d): ?array
    {
        $money = $d['money'] ?? [];
        $ratio = $money['cashout_ratio'] ?? null;

        if ($ratio === null || ($money['earned_total'] ?? 0) < $this->t('cashout_min_earned') || $ratio < $this->t('cashout_ratio')) {
            return null;
        }

        return $this->flag('cash_out_only', 'low', 'Cashes out nearly everything earned',
            sprintf('%d%% of earned credits were converted to cash.', round($ratio * 100)),
            ['earned' => $money['earned_total'], 'cashed_out' => $money['cashout_total']]);
    }

    private function listenCapLowEffort(array $d): ?array
    {
        $cap = $this->t('listen_cap_credits');
        $max = $this->t('listen_low_effort_seconds');

        $days = collect($d['listening']['listen_earn'] ?? [])
            ->filter(fn ($r) => $r['amount'] >= $cap && $r['seconds'] !== null && $r['seconds'] <= $max);

        if ($days->count() < $this->t('listen_low_effort_min_days')) {
            return null;
        }

        return $this->flag('listen_cap_low_effort', 'medium', 'Full listen-to-earn payout for minimal listening',
            "{$days->count()} days paid the daily cap for {$max} seconds of listening or less. The pool does not scale payout with listening time.",
            ['days' => $days->count(), 'min_seconds' => $days->min('seconds')]);
    }

    private function inactiveReferees(array $d): ?array
    {
        $referees = collect($d['network']['referees'] ?? []);

        if ($referees->count() < $this->t('inactive_referee_min')) {
            return null;
        }

        $inactive = $referees->filter(fn ($r) => empty($r['last_login_at']) || ($r['plays'] ?? 0) === 0);

        if ($inactive->count() / $referees->count() < $this->t('inactive_referee_ratio')) {
            return null;
        }

        return $this->flag('inactive_referees', 'medium', 'Referred accounts show no real activity',
            "{$inactive->count()} of {$referees->count()} referred accounts never logged in or never played anything.",
            ['referee_ids' => $inactive->pluck('id')->values()->all()]);
    }

    private function blockedRequests(array $d): ?array
    {
        $count = $d['security']['blocked_count'] ?? 0;

        if ($count < $this->t('blocked_requests')) {
            return null;
        }

        return $this->flag('blocked_requests', 'medium', 'Repeated blocked requests',
            "{$count} requests were blocked by the security rules.",
            ['routes' => collect($d['security']['blocked_routes'] ?? [])->take(5)->all()]);
    }

    private function rateLimited(array $d): ?array
    {
        $count = $d['security']['rate_limited_count'] ?? 0;

        if ($count < $this->t('rate_limited_requests')) {
            return null;
        }

        return $this->flag('rate_limited', 'low', 'Hit rate limits repeatedly', "{$count} requests were rate limited.");
    }

    private function failedWithdrawals(array $d): ?array
    {
        $count = $d['money']['withdrawals_failed_count'] ?? 0;

        if ($count < $this->t('failed_withdrawals')) {
            return null;
        }

        return $this->flag('failed_withdrawals', 'low', 'Multiple failed withdrawals', "{$count} withdrawal attempts failed.");
    }

    private function noGoldTest(array $d): ?array
    {
        $c = $d['contributions'] ?? [];

        if (! ($c['available'] ?? false) || ($c['submissions'] ?? 0) < $this->t('contribution_min_for_gold') || ($c['gold_attempts'] ?? 1) > 0) {
            return null;
        }

        return $this->flag('no_gold_test', 'low', 'Contribution quality never tested',
            "{$c['submissions']} submissions were all paid without a single gold-standard check.");
    }

    private function noTwoFactorWithPayouts(array $d): ?array
    {
        if (($d['identity']['two_factor_enabled'] ?? true) || ($d['money']['withdrawals_completed_total'] ?? 0) <= 0) {
            return null;
        }

        return $this->flag('no_two_factor_with_payouts', 'low', 'Receives payouts without 2FA',
            'The account withdraws real money but has no second factor protecting it.');
    }
}
