<?php

namespace App\Console\Commands;

use App\Services\Commerce\SettlementPayoutService;
use App\Services\Commerce\SettlementService;
use Illuminate\Console\Command;

class ClearDueSettlements extends Command
{
    protected $signature = 'commerce:clear-due-settlements';

    protected $description = 'Clear settlements past their hold window and pay cleared earnings into wallets';

    public function handle(SettlementService $settlements, SettlementPayoutService $payouts): int
    {
        $cleared = $settlements->clearDue();
        $this->info("Cleared {$cleared} settlement(s).");

        $result = $payouts->payDue();
        $this->info("Paid {$result['paid']} settlement(s) into wallets, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
