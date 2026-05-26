<?php

namespace App\Jobs\Observability;

use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists a security event off the request path so telemetry never adds
 * latency to (or fails) the action that produced it.
 */
class RecordSecurityEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $payload  Output of {@see SecurityEvent::toArray()}.
     */
    public function __construct(
        protected array $payload,
    ) {}

    public function handle(SecurityEventRecorder $recorder): void
    {
        $recorder->record(SecurityEvent::fromArray($this->payload));
    }
}
