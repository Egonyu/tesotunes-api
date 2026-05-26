<?php

namespace App\Console\Commands;

use App\Services\Observability\Detection\DetectionRuleEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Runs the security detection-rule engine. Scheduled every five minutes;
 * looks back over a wider window so sustained attacks are not missed between
 * runs (deduplication is handled by the day-bucketed incident key).
 */
class ObservabilityDetectCommand extends Command
{
    protected $signature = 'observability:detect {--window=30 : Minutes of recent events to evaluate}';

    protected $description = 'Evaluate security detection rules and open/refresh observability incidents';

    public function handle(DetectionRuleEngine $engine): int
    {
        $window = max(1, (int) $this->option('window'));

        $result = $engine->run(Carbon::now()->subMinutes($window));

        $this->table(
            ['Metric', 'Value'],
            [
                ['rules_evaluated', $result['rules']],
                ['incidents_opened', $result['incidents_opened']],
                ['incidents_updated', $result['incidents_updated']],
            ],
        );

        return self::SUCCESS;
    }
}
