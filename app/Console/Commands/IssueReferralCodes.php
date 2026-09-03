<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Give every existing account a referral code.
 *
 * Codes were minted lazily by a model accessor that nothing ever called, so no
 * account on the platform had one — a referral programme nobody can share.
 * Registration now issues a code on signup; this covers everyone who joined
 * before that.
 *
 * Safe to re-run: accounts that already have a code are skipped.
 */
class IssueReferralCodes extends Command
{
    protected $signature = 'referrals:issue-codes {--dry-run : Report what would change without writing}';

    protected $description = 'Issue a referral code to every account that has none';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $missing = User::query()->whereNull('referral_code')->get(['id', 'name']);

        if ($missing->isEmpty()) {
            $this->info('Every account already has a referral code.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Would issue' : 'Issuing').' codes to '.$missing->count().' accounts.');

        if ($dryRun) {
            return self::SUCCESS;
        }

        $issued = 0;
        $failed = 0;

        foreach ($missing as $user) {
            try {
                $user->generateReferralCode();
                $issued++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info("Issued {$issued} codes.".($failed ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
