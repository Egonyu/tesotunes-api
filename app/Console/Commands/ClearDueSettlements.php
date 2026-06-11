<?php

namespace App\Console\Commands;

use App\Services\Commerce\SettlementService;
use Illuminate\Console\Command;

class ClearDueSettlements extends Command
{
    protected $signature = 'commerce:clear-due-settlements';

    protected $description = 'Promote pending settlements whose hold window has passed to cleared';

    public function handle(SettlementService $settlements): int
    {
        $cleared = $settlements->clearDue();

        $this->info("Cleared {$cleared} settlement(s).");

        return self::SUCCESS;
    }
}
