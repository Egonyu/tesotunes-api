<?php

namespace App\Console\Commands;

use App\Modules\Contributions\Services\AtesoTranslatorClient;
use Illuminate\Console\Command;

/**
 * Keeps the hosted Ateso translator awake.
 *
 * The Space runs on free CPU with a 48-hour idle timeout. Once it sleeps, the
 * next request pays a one-to-three-minute cold start while the container
 * restarts and reloads a 2.4 GB model — and the person paying that cost is a
 * visitor on a phone deciding whether this is worth their time. A cheap ping on
 * a schedule means it is never them.
 */
class PingAtesoTranslatorCommand extends Command
{
    protected $signature = 'ateso:ping-translator';

    protected $description = 'Keep the hosted Ateso translator from going idle';

    public function handle(): int
    {
        if (! config('contributions.translator.keep_warm', true)) {
            $this->line('Keep-warm disabled; skipping.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $ok = AtesoTranslatorClient::fromConfig()->ping();
        $ms = (int) ((microtime(true) - $started) * 1000);

        if (! $ok) {
            $this->error("Translator unreachable after {$ms}ms.");

            return self::FAILURE;
        }

        $this->info("Translator awake ({$ms}ms).");

        return self::SUCCESS;
    }
}
