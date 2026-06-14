<?php

namespace App\Modules\Contributions\Services;

use App\Modules\Contributions\Models\CorpusPair;
use Illuminate\Support\Facades\Storage;

/**
 * Produces the versioned, licensed corpus export that feeds ateso-nlp. One
 * JSONL line per accepted pair, carrying provenance + region + license +
 * quality, and never any PII (only internal pseudonymous contributor ids).
 */
class CorpusExportService
{
    /**
     * Export accepted pairs to a versioned JSONL file on the given disk.
     *
     * @return array{version: string, path: string, count: int}
     */
    public function export(?string $version = null, string $disk = 'local'): array
    {
        $version = $version ?: 'v'.now()->format('Ymd_His');
        $relativePath = "exports/ateso-corpus-{$version}.jsonl";

        $lines = [];
        $count = 0;

        CorpusPair::query()
            ->orderBy('id')
            ->chunkById(500, function ($pairs) use (&$lines, &$count, $version) {
                foreach ($pairs as $pair) {
                    $lines[] = json_encode($this->row($pair, $version), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $count++;
                }

                // Stamp the version + export time so re-exports are traceable.
                CorpusPair::query()->whereIn('id', $pairs->pluck('id'))->update([
                    'corpus_version' => $version,
                    'exported_at' => now(),
                ]);
            });

        Storage::disk($disk)->put($relativePath, implode("\n", $lines).($count > 0 ? "\n" : ''));

        return [
            'version' => $version,
            'path' => $relativePath,
            'count' => $count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CorpusPair $pair, string $version): array
    {
        return [
            'en' => $pair->en_text,
            'ateso' => $pair->ateso_text,
            'region' => $pair->region,
            'register' => $pair->register,
            'quality_score' => (float) $pair->quality_score,
            'license' => $pair->license_version,
            'corpus_version' => $version,
            'provenance' => $pair->provenance,
        ];
    }
}
