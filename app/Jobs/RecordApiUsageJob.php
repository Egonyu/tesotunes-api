<?php

namespace App\Jobs;

use App\Models\ApiUsageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes API usage log entries asynchronously to avoid blocking requests.
 */
class RecordApiUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        protected array $data,
    ) {}

    public function handle(): void
    {
        ApiUsageLog::create($this->data);
    }
}
