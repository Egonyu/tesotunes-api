<?php

namespace App\Modules\Contributions\Services;

use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\SongLyricOptIn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an artist's per-song opt-in into translation tasks — one task per
 * distinct lyric line, sourced from the song. Withdrawing closes any of the
 * song's still-open tasks but never deletes work already submitted.
 */
class LyricOptInService
{
    /**
     * Opt a song's lyrics into the task pool and generate per-line translate
     * tasks. Idempotent: re-opting a song reactivates it and tops up tasks for
     * any lines that don't yet have one.
     */
    public function optIn(Song $song, User $by, ?string $sourceLang = null, ?string $targetLang = null): SongLyricOptIn
    {
        $lines = $this->lyricLines($song);

        return DB::transaction(function () use ($song, $by, $sourceLang, $targetLang, $lines) {
            $sourceLang ??= $song->primary_language ?: (string) config('contributions.languages.target');
            $targetLang ??= (string) config('contributions.languages.source');

            $optIn = SongLyricOptIn::query()->firstOrNew(['song_id' => $song->id]);
            $optIn->forceFill([
                'opted_in_by_user_id' => $by->id,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'region' => $optIn->region ?: config('contributions.default_region'),
                'status' => SongLyricOptIn::STATUS_ACTIVE,
                'opted_in_at' => $optIn->opted_in_at ?? now(),
                'withdrawn_at' => null,
            ])->save();

            $generated = 0;
            foreach ($lines as $line) {
                $exists = ContributionTask::query()
                    ->where('source_type', $song->getMorphClass())
                    ->where('source_id', $song->getKey())
                    ->where('prompt_text', $line)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $task = new ContributionTask([
                    'type' => ContributionTask::TYPE_TRANSLATE,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'region' => $optIn->region,
                    'register' => 'lyrical',
                    'prompt_text' => $line,
                    'redundancy_target' => (int) config('contributions.redundancy_target', 3),
                    'status' => ContributionTask::STATUS_OPEN,
                ]);
                $task->source()->associate($song);
                $task->save();
                $generated++;
            }

            $optIn->forceFill(['tasks_generated' => $optIn->tasks_generated + $generated])->save();

            return $optIn->refresh();
        });
    }

    /**
     * Withdraw a song from the pool and close any of its still-open tasks.
     * Submitted/fulfilled work is preserved.
     */
    public function withdraw(Song $song): ?SongLyricOptIn
    {
        $optIn = SongLyricOptIn::query()->where('song_id', $song->id)->first();

        if (! $optIn) {
            return null;
        }

        return DB::transaction(function () use ($song, $optIn) {
            ContributionTask::query()
                ->where('source_type', $song->getMorphClass())
                ->where('source_id', $song->getKey())
                ->where('status', ContributionTask::STATUS_OPEN)
                ->update(['status' => ContributionTask::STATUS_CLOSED]);

            $optIn->forceFill([
                'status' => SongLyricOptIn::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
            ])->save();

            return $optIn->refresh();
        });
    }

    /**
     * Distinct, trimmed, non-empty lyric lines for a song.
     *
     * @return Collection<int, string>
     */
    public function lyricLines(Song $song): Collection
    {
        return Str::of((string) $song->lyrics)
            ->replace("\r\n", "\n")
            ->explode("\n")
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->unique()
            ->values();
    }
}
