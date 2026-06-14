<?php

namespace App\Console\Commands;

use App\Modules\Contributions\Services\DailyChallengeService;
use Illuminate\Console\Command;

/**
 * Publishes the Ateso daily challenge task (idempotent). Scheduled daily; also
 * runnable on demand. No-ops quietly when the module is disabled.
 */
class PublishDailyChallengeCommand extends Command
{
    protected $signature = 'contributions:daily-challenge';

    protected $description = 'Publish today\'s Ateso translation daily challenge';

    public function handle(DailyChallengeService $service): int
    {
        if (! config('contributions.enabled', false)) {
            $this->info('Contributions module disabled — skipping.');

            return self::SUCCESS;
        }

        $task = $service->publishToday();

        if (! $task) {
            $this->warn('No daily-challenge rotation configured.');

            return self::SUCCESS;
        }

        $this->info("Daily challenge ready: \"{$task->prompt_text}\" ({$task->metadata['theme']}).");

        return self::SUCCESS;
    }
}
