<?php

namespace App\Console\Commands\Loyalty;

use App\Models\Loyalty\LoyaltyCardMember;
use App\Services\Loyalty\MembershipService;
use Illuminate\Console\Command;

class ProcessRenewals extends Command
{
    protected $signature = 'loyalty:process-renewals
                            {--dry-run : Show what would be renewed without executing}';

    protected $description = 'Process auto-renewals for expiring loyalty card memberships';

    public function handle(MembershipService $membershipService): int
    {
        $graceDays = config('loyalty.renewal.grace_period_days', 3);

        $expiring = LoyaltyCardMember::where('status', 'active')
            ->where('auto_renew', true)
            ->where('expires_at', '<=', now()->addDays($graceDays))
            ->with('loyaltyCard', 'user')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No memberships need renewal.');

            return self::SUCCESS;
        }

        $this->info("Found {$expiring->count()} membership(s) to renew.");

        $renewed = 0;
        $failed  = 0;

        foreach ($expiring as $member) {
            $label = "User #{$member->user_id} — {$member->loyaltyCard->name} ({$member->tier})";

            if ($this->option('dry-run')) {
                $this->line("  [DRY-RUN] Would renew: {$label}");

                continue;
            }

            try {
                $membershipService->renew($member);
                $renewed++;
                $this->line("  ✓ Renewed: {$label}");
            } catch (\Exception $e) {
                $failed++;
                $this->error("  ✗ Failed: {$label} — {$e->getMessage()}");
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Completed: {$renewed} renewed, {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
