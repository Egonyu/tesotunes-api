<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Async increment/decrement of counter columns.
 *
 * Uses raw DB::table to avoid model boot overhead and ensure
 * a single atomic UPDATE per counter. Dispatched onto the
 * 'counters' queue so it never blocks the HTTP response.
 *
 * Usage:
 *   IncrementCounter::dispatch('songs', $songId, 'play_count');
 *   IncrementCounter::dispatch('songs', $songId, 'download_count', 1);
 *   IncrementCounter::dispatch('artists', $artistId, 'followers_count', -1); // decrement
 */
class IncrementCounter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $table,
        public readonly int $modelId,
        public readonly string $column,
        public readonly int $amount = 1,
    ) {
        $this->onQueue('counters');
    }

    public function handle(): void
    {
        if ($this->amount >= 0) {
            DB::table($this->table)
                ->where('id', $this->modelId)
                ->increment($this->column, $this->amount);
        } else {
            DB::table($this->table)
                ->where('id', $this->modelId)
                ->decrement($this->column, abs($this->amount));
        }
    }
}
