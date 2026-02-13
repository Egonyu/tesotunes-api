<?php

namespace App\Modules\Sacco\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Sacco\Services\SaccoLoanService;

class CheckOverdueLoans extends Command
{
    protected $signature = 'sacco:check-overdue';
    protected $description = 'Check and mark overdue loans';

    public function handle(SaccoLoanService $loanService): int
    {
        if (!config('sacco.enabled', false)) {
            $this->warn('SACCO module is disabled');
            return 1;
        }

        $this->info('Checking for overdue loans...');

        $markedOverdue = $loanService->checkOverdueLoans();

        $this->info("Marked {$markedOverdue} loans as overdue");

        return 0;
    }
}
