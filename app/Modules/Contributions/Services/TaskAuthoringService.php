<?php

namespace App\Modules\Contributions\Services;

use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Admin authoring of curated translation prompts — the primary, directed way to
 * fill the task pool (lyrics opt-in and the daily challenge are supplements).
 * Admins choose the direction and register so the corpus can be steered toward
 * the gaps it actually has.
 */
class TaskAuthoringService
{
    /**
     * Resolve a friendly direction token to source/target languages.
     *
     * @return array{0: string, 1: string} [source_lang, target_lang]
     */
    public function resolveDirection(string $direction): array
    {
        $en = (string) config('contributions.languages.source', 'en');
        $teo = (string) config('contributions.languages.target', 'teo');

        return match ($direction) {
            'en_to_teo' => [$en, $teo], // show English, translate to Ateso
            default => [$teo, $en],     // teo_to_en — show Ateso, translate to English
        };
    }

    /**
     * Create one curated translate task. Idempotent on (prompt, source_lang).
     */
    public function create(string $prompt, string $direction, ?string $register = null, ?string $region = null): ContributionTask
    {
        [$source, $target] = $this->resolveDirection($direction);
        $prompt = trim($prompt);

        return ContributionTask::query()->firstOrCreate(
            ['prompt_text' => $prompt, 'source_lang' => $source],
            [
                'type' => ContributionTask::TYPE_TRANSLATE,
                'target_lang' => $target,
                'region' => $region ?: config('contributions.default_region'),
                'register' => $register,
                'redundancy_target' => (int) config('contributions.redundancy_target', 3),
                'status' => ContributionTask::STATUS_OPEN,
            ]
        );
    }

    /**
     * Bulk-import a batch of prompts sharing one direction/register. Blank lines
     * and within-batch duplicates are dropped; prompts that already exist are
     * skipped.
     *
     * @param  iterable<string>  $prompts
     * @return array{created: int, skipped: int}
     */
    public function import(iterable $prompts, string $direction, ?string $register = null, ?string $region = null): array
    {
        [$source] = $this->resolveDirection($direction);

        $clean = collect($prompts)
            ->map(fn ($p) => trim((string) $p))
            ->filter(fn ($p) => $p !== '')
            ->unique(fn ($p) => TextNormalizer::key($p))
            ->values();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($clean, $direction, $register, $region, $source, &$created, &$skipped) {
            foreach ($clean as $prompt) {
                $exists = ContributionTask::query()
                    ->where('prompt_text', $prompt)
                    ->where('source_lang', $source)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $this->create($prompt, $direction, $register, $region);
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
