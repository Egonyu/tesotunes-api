<?php

namespace App\Console\Commands;

use App\Modules\Contributions\Services\CorpusExportService;
use Illuminate\Console\Command;

/**
 * Exports the accepted EN<->Ateso corpus to a versioned JSONL file for
 * ateso-nlp. Admin tooling — runs regardless of CONTRIBUTIONS_ENABLED.
 */
class ExportAtesoCorpusCommand extends Command
{
    // Note: the version label flag is --tag, not --version: Symfony Console
    // reserves --version globally, so it never reaches the command.
    protected $signature = 'corpus:export {--tag= : Corpus version label (defaults to a timestamp)} {--disk=local : Filesystem disk to write to}';

    protected $description = 'Export accepted EN<->Ateso corpus pairs to a versioned JSONL file';

    public function handle(CorpusExportService $exporter): int
    {
        $result = $exporter->export($this->option('tag'), (string) $this->option('disk'));

        $this->info("Exported {$result['count']} corpus pair(s) as version {$result['version']}.");
        $this->line("File: {$result['path']} (disk: {$this->option('disk')})");

        return self::SUCCESS;
    }
}
