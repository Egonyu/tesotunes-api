<?php

namespace App\Console\Commands;

use App\Modules\Store\Services\DealService;
use Illuminate\Console\Command;

class ExpireDealClaims extends Command
{
    protected $signature = 'deals:expire-claims';

    protected $description = 'Expire unredeemed deal claims past their window and refund their credits';

    public function handle(DealService $deals): int
    {
        $count = $deals->expireStale();
        $this->info("Expired {$count} deal claim(s).");

        return self::SUCCESS;
    }
}
