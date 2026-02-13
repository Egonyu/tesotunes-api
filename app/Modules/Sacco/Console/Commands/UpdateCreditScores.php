<?php

namespace App\Modules\Sacco\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Sacco\Services\SaccoCreditScoreService;

class UpdateCreditScores extends Command
{
    protected $signature = 'sacco:update-credit-scores';
    protected $description = 'Update credit scores for all active members';

    public function handle(SaccoCreditScoreService $creditScoreService): int
    {
        if (!config('sacco.enabled', false)) {
            $this->warn('SACCO module is disabled');
            return 1;
        }

        $this->info('Updating credit scores for all active members...');

        $updated = $creditScoreService->updateAllCreditScores();

        $this->info("Updated credit scores for {$updated} members");

        return 0;
    }
}
