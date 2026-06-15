<?php

namespace App\Console\Commands;

use App\Modules\Contributions\Services\TaskAuthoringService;
use Illuminate\Console\Command;

/**
 * Bulk-imports the curated English prompt set (docs/ateso-corpus/prompts/all.json)
 * as English -> Ateso translation tasks, grouped by register. Idempotent —
 * prompts that already exist are skipped, so it is safe to re-run.
 */
class ImportAtesoPromptsCommand extends Command
{
    protected $signature = 'corpus:import-prompts
        {--file= : Path to the prompts JSON (defaults to the bundled set)}
        {--direction=en_to_teo : en_to_teo (show English) or teo_to_en}';

    protected $description = 'Bulk-import curated English prompts as Ateso translation tasks';

    public function handle(TaskAuthoringService $authoring): int
    {
        $file = $this->option('file') ?: base_path('docs/ateso-corpus/prompts/all.json');

        if (! is_file($file)) {
            $this->error("Prompts file not found: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($file), true);
        if (! is_array($rows)) {
            $this->error('Prompts file is not valid JSON.');

            return self::FAILURE;
        }

        // Group prompts by register so each batch carries the right tag.
        $byRegister = [];
        foreach ($rows as $row) {
            $register = $row['register'] ?? 'general';
            $byRegister[$register][] = (string) ($row['s'] ?? '');
        }

        $direction = (string) $this->option('direction');
        $created = 0;
        $skipped = 0;

        foreach ($byRegister as $register => $prompts) {
            $result = $authoring->import($prompts, $direction, $register);
            $created += $result['created'];
            $skipped += $result['skipped'];
            $this->line(sprintf('%-14s +%d created, %d skipped', $register, $result['created'], $result['skipped']));
        }

        $this->info("Done — {$created} task(s) created, {$skipped} already existed.");

        return self::SUCCESS;
    }
}
