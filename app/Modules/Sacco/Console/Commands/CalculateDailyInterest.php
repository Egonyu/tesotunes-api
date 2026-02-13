<?php

namespace App\Modules\Sacco\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Sacco\Services\SaccoInterestService;

class CalculateDailyInterest extends Command
{
    protected $signature = 'sacco:calculate-interest';
    protected $description = 'Calculate and credit daily interest for all savings accounts';

    public function handle(SaccoInterestService $interestService): int
    {
        if (!config('sacco.enabled', false)) {
            $this->warn('SACCO module is disabled');
            return 1;
        }

        $this->info('Calculating daily interest for savings accounts...');

        $results = $interestService->creditDailyInterest();

        $this->info("Processed: {$results['processed']} accounts");
        $this->info("Total Interest: UGX " . number_format($results['total_interest'], 2));

        if (!empty($results['errors'])) {
            $this->warn("\nErrors encountered:");
            foreach ($results['errors'] as $error) {
                $this->error($error);
            }
        }

        return 0;
    }
}
