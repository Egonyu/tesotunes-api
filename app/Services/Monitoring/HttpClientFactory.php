<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Factory for pre-configured HTTP clients with retry, backoff,
 * timeouts, and performance metric collection.
 *
 * Usage:
 *   $response = HttpClientFactory::make('zengapay')
 *       ->post('https://api.zengapay.com/v1/collections', [...]);
 *
 *   $response = HttpClientFactory::forService('expo_push', 30)
 *       ->post('https://exp.host/--/api/v2/push/send', [...]);
 */
class HttpClientFactory
{
    /**
     * Pre-defined service profiles with sane defaults.
     *
     * retries:       number of retry attempts
     * backoff_ms:    initial delay between retries (doubles each time)
     * timeout:       connect + response timeout in seconds
     * retry_on:      HTTP status codes that trigger a retry
     */
    private static array $profiles = [
        'zengapay' => [
            'retries'    => 3,
            'backoff_ms' => 500,
            'timeout'    => 15,
        ],
        'mtn_momo' => [
            'retries'    => 3,
            'backoff_ms' => 1000,
            'timeout'    => 30,
        ],
        'airtel_money' => [
            'retries'    => 3,
            'backoff_ms' => 1000,
            'timeout'    => 30,
        ],
        'expo_push' => [
            'retries'    => 2,
            'backoff_ms' => 500,
            'timeout'    => 30,
        ],
        'africastalking' => [
            'retries'    => 2,
            'backoff_ms' => 300,
            'timeout'    => 15,
        ],
        'twilio' => [
            'retries'    => 2,
            'backoff_ms' => 300,
            'timeout'    => 15,
        ],
        'rss_feed' => [
            'retries'    => 2,
            'backoff_ms' => 1000,
            'timeout'    => 30,
        ],
        'default' => [
            'retries'    => 2,
            'backoff_ms' => 300,
            'timeout'    => 15,
        ],
    ];

    // HTTP codes that are safe to retry (server-side / transient errors)
    private static array $retryableStatuses = [408, 429, 500, 502, 503, 504];

    /**
     * Create a PendingRequest with retry + exponential backoff for a named service.
     */
    public static function make(string $service = 'default'): PendingRequest
    {
        $profile = self::$profiles[$service] ?? self::$profiles['default'];

        return self::build($service, $profile['timeout'], $profile['retries'], $profile['backoff_ms']);
    }

    /**
     * Create a PendingRequest with custom timeout but service-level retry settings.
     */
    public static function forService(string $service, int $timeout = null): PendingRequest
    {
        $profile = self::$profiles[$service] ?? self::$profiles['default'];
        $timeout = $timeout ?? $profile['timeout'];

        return self::build($service, $timeout, $profile['retries'], $profile['backoff_ms']);
    }

    /**
     * Quick one-off with explicit parameters (no profile lookup).
     */
    public static function withRetry(int $retries = 2, int $backoffMs = 300, int $timeout = 15): PendingRequest
    {
        return self::build('custom', $timeout, $retries, $backoffMs);
    }

    /**
     * Build the PendingRequest with all instrumentation.
     */
    private static function build(string $service, int $timeout, int $retries, int $backoffMs): PendingRequest
    {
        $startTime = microtime(true);

        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->retry(
                times: $retries,
                sleepMilliseconds: function (int $attempt) use ($backoffMs) {
                    // Exponential backoff: 500 → 1000 → 2000 …
                    $delay = $backoffMs * (2 ** ($attempt - 1));
                    // Add jitter (±25%) to prevent thundering herd
                    $jitter = (int) ($delay * 0.25 * (mt_rand(-100, 100) / 100));
                    return $delay + $jitter;
                },
                when: function (\Exception $exception, PendingRequest $request) use ($service) {
                    $shouldRetry = self::shouldRetry($exception);

                    if ($shouldRetry) {
                        Log::warning("HTTP retry triggered for [{$service}]", [
                            'service' => $service,
                            'error'   => $exception->getMessage(),
                        ]);
                    }

                    return $shouldRetry;
                },
                throw: true,
            )
            ->beforeSending(function () use (&$startTime) {
                $startTime = microtime(true);
            })
            ->withOptions([
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use ($service) {
                    self::recordMetrics($service, $stats);
                },
            ]);
    }

    /**
     * Decide if an exception is retryable.
     */
    private static function shouldRetry(\Exception $exception): bool
    {
        // Connection exceptions are always retryable
        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }

        // Request exceptions carry the response — check status code
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $status = $exception->response?->status();
            return $status && in_array($status, self::$retryableStatuses);
        }

        return false;
    }

    /**
     * Record transfer metrics into cache for the monitoring dashboard.
     */
    private static function recordMetrics(string $service, \GuzzleHttp\TransferStats $stats): void
    {
        try {
            $duration = $stats->getTransferTime();
            $statusCode = $stats->getResponse()?->getStatusCode();
            $uri = (string) $stats->getEffectiveUri();

            // Daily bucket key
            $dateKey = now()->format('Y-m-d');
            $cacheKey = "http_metrics:{$service}:{$dateKey}";

            $metrics = cache()->get($cacheKey, [
                'total_requests'  => 0,
                'total_duration'  => 0,
                'max_duration'    => 0,
                'failures'        => 0,
                'retries'         => 0,
                'status_codes'    => [],
            ]);

            $metrics['total_requests']++;
            $metrics['total_duration'] += $duration;
            $metrics['max_duration'] = max($metrics['max_duration'], $duration);

            if ($statusCode) {
                $bucket = (string) $statusCode;
                $metrics['status_codes'][$bucket] = ($metrics['status_codes'][$bucket] ?? 0) + 1;

                if ($statusCode >= 400) {
                    $metrics['failures']++;
                }
            }

            cache()->put($cacheKey, $metrics, 86400);

            // Alert on sustained failures
            if ($metrics['failures'] > 0 && $metrics['total_requests'] > 5) {
                $errorRate = $metrics['failures'] / $metrics['total_requests'];
                if ($errorRate > 0.5) {
                    app(AlertingService::class)->high(
                        "http_error_rate_{$service}",
                        "High HTTP error rate for {$service}: " . round($errorRate * 100, 1) . '%',
                        [
                            'service' => $service,
                            'error_rate' => round($errorRate * 100, 1) . '%',
                            'total_requests' => $metrics['total_requests'],
                            'failures' => $metrics['failures'],
                        ]
                    );
                }
            }
        } catch (\Throwable) {
            // Instrumentation must never throw
        }
    }

    /**
     * Retrieve collected metrics for a specific service and date.
     */
    public static function getMetrics(string $service, string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        $metrics = cache()->get("http_metrics:{$service}:{$date}", []);

        if (!empty($metrics) && $metrics['total_requests'] > 0) {
            $metrics['avg_duration'] = round($metrics['total_duration'] / $metrics['total_requests'], 4);
        }

        return $metrics;
    }

    /**
     * Retrieve metrics for all tracked services.
     */
    public static function getAllMetrics(string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        $all = [];

        foreach (array_keys(self::$profiles) as $service) {
            $metrics = self::getMetrics($service, $date);
            if (!empty($metrics)) {
                $all[$service] = $metrics;
            }
        }

        return $all;
    }
}
