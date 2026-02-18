<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcileStuckPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-stuck {--minutes=15 : Minutes threshold for stuck payments}';
    protected $description = 'Reconcile payments stuck in processing status by checking ZengaPay';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        $this->info("Reconciling payments stuck for more than {$minutes} minutes...");

        $service = app(PaymentReconciliationService::class);
        $results = $service->reconcileStuck($minutes);

        $this->table(
            ['Metric', 'Count'],
            collect($results)->map(fn($v, $k) => [ucfirst(str_replace('_', ' ', $k)), $v])->values()->toArray()
        );

        if ($results['resolved'] > 0) {
            $this->info("Successfully resolved {$results['resolved']} stuck payments.");
        }

        if ($results['errors'] > 0) {
            $this->warn("{$results['errors']} payments could not be checked. See logs.");
        }

        return self::SUCCESS;
    }
}
