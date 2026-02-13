<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central alerting service — routes alerts to Slack, email, logs,
 * and tracks alert frequency to prevent spam.
 *
 * Usage:
 *   app(AlertingService::class)->critical('payment_gateway_down', 'ZengaPay API unreachable', [...]);
 *   app(AlertingService::class)->warning('queue_backlog', '150 jobs pending', [...]);
 */
class AlertingService
{
    // Severity levels
    public const CRITICAL = 'critical';   // Pager-worthy: revenue loss, data loss, full outage
    public const HIGH     = 'high';       // Needs attention within an hour
    public const WARNING  = 'warning';    // Should investigate today
    public const INFO     = 'info';       // FYI — logged only

    // Cooldown periods per alert key (prevents flooding)
    private array $cooldowns = [
        self::CRITICAL => 300,    // 5 minutes between same critical alerts
        self::HIGH     => 900,    // 15 minutes
        self::WARNING  => 3600,   // 1 hour
        self::INFO     => 3600,   // 1 hour
    ];

    /**
     * Send a critical alert — Slack + email + log.
     */
    public function critical(string $alertKey, string $message, array $context = []): void
    {
        $this->alert(self::CRITICAL, $alertKey, $message, $context);
    }

    /**
     * Send a high-severity alert — Slack + log.
     */
    public function high(string $alertKey, string $message, array $context = []): void
    {
        $this->alert(self::HIGH, $alertKey, $message, $context);
    }

    /**
     * Send a warning — Slack + log.
     */
    public function warning(string $alertKey, string $message, array $context = []): void
    {
        $this->alert(self::WARNING, $alertKey, $message, $context);
    }

    /**
     * Send an info alert — log only.
     */
    public function info(string $alertKey, string $message, array $context = []): void
    {
        $this->alert(self::INFO, $alertKey, $message, $context);
    }

    /**
     * Core alert dispatcher.
     */
    public function alert(string $severity, string $alertKey, string $message, array $context = []): void
    {
        // Throttle: skip if same alert fired recently
        if ($this->isThrottled($severity, $alertKey)) {
            return;
        }

        $this->markFired($severity, $alertKey);
        $this->recordMetric($severity, $alertKey);

        $payload = [
            'severity' => $severity,
            'alert_key' => $alertKey,
            'message' => $message,
            'context' => $context,
            'environment' => app()->environment(),
            'server' => gethostname(),
            'timestamp' => now()->toIso8601String(),
            'url' => request()?->fullUrl(),
        ];

        // Always log
        $logLevel = match ($severity) {
            self::CRITICAL => 'critical',
            self::HIGH     => 'error',
            self::WARNING  => 'warning',
            default        => 'info',
        };

        Log::channel('security')->{$logLevel}("[ALERT:{$severity}] {$alertKey}: {$message}", $payload);

        // Slack — for critical, high, and warning
        if (in_array($severity, [self::CRITICAL, self::HIGH, self::WARNING])) {
            $this->sendToSlack($severity, $alertKey, $message, $context);
        }

        // Email — for critical only
        if ($severity === self::CRITICAL) {
            $this->sendEmail($alertKey, $message, $context);
        }
    }

    /**
     * Send a Slack webhook notification.
     */
    protected function sendToSlack(string $severity, string $alertKey, string $message, array $context): void
    {
        $webhookUrl = config('logging.channels.slack.url') ?: env('LOG_SLACK_WEBHOOK_URL');

        if (!$webhookUrl) {
            return;
        }

        $emoji = match ($severity) {
            self::CRITICAL => ':rotating_light:',
            self::HIGH     => ':warning:',
            self::WARNING  => ':eyes:',
            default        => ':information_source:',
        };

        $color = match ($severity) {
            self::CRITICAL => '#FF0000',
            self::HIGH     => '#FF8C00',
            self::WARNING  => '#FFD700',
            default        => '#36A64F',
        };

        $contextFields = collect($context)->take(10)->map(fn ($v, $k) => [
            'title' => (string) $k,
            'value' => is_array($v) ? json_encode($v, JSON_PRETTY_PRINT) : (string) $v,
            'short' => strlen((string) ($v ?? '')) < 40,
        ])->values()->toArray();

        try {
            Http::timeout(5)->post($webhookUrl, [
                'text' => "{$emoji} *[{$severity}] {$alertKey}*",
                'attachments' => [[
                    'color' => $color,
                    'text' => $message,
                    'fields' => array_merge($contextFields, [
                        ['title' => 'Environment', 'value' => app()->environment(), 'short' => true],
                        ['title' => 'Time', 'value' => now()->toDateTimeString(), 'short' => true],
                    ]),
                    'footer' => 'TesoTunes API Alerting',
                    'ts' => now()->timestamp,
                ]],
            ]);
        } catch (\Throwable $e) {
            // Don't let Slack failures cascade
            Log::error('Failed to send Slack alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send an email alert to configured admin recipients.
     */
    protected function sendEmail(string $alertKey, string $message, array $context): void
    {
        $recipients = array_filter(explode(',', env('ALERT_EMAIL_RECIPIENTS', '')));

        if (empty($recipients)) {
            return;
        }

        try {
            foreach ($recipients as $email) {
                Mail::raw(
                    "[CRITICAL ALERT] {$alertKey}\n\n{$message}\n\n"
                    . "Context:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    . "\n\nServer: " . gethostname()
                    . "\nEnvironment: " . app()->environment()
                    . "\nTime: " . now()->toDateTimeString(),
                    fn ($m) => $m->to(trim($email))->subject("[CRITICAL] TesoTunes: {$alertKey}")
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send alert email', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if an alert is currently throttled.
     */
    protected function isThrottled(string $severity, string $alertKey): bool
    {
        $cacheKey = "alert:throttle:{$severity}:{$alertKey}";
        return Cache::has($cacheKey);
    }

    /**
     * Mark alert as fired (starts cooldown).
     */
    protected function markFired(string $severity, string $alertKey): void
    {
        $ttl = $this->cooldowns[$severity] ?? 3600;
        Cache::put("alert:throttle:{$severity}:{$alertKey}", true, $ttl);
    }

    /**
     * Record alert metric for tracking frequency.
     */
    protected function recordMetric(string $severity, string $alertKey): void
    {
        $dateKey = now()->format('Y-m-d');
        $metricsKey = "alert:metrics:{$dateKey}";

        try {
            $metrics = Cache::get($metricsKey, []);
            $key = "{$severity}:{$alertKey}";
            $metrics[$key] = ($metrics[$key] ?? 0) + 1;
            $metrics['_total'] = ($metrics['_total'] ?? 0) + 1;
            $metrics['_last_at'] = now()->toIso8601String();
            Cache::put($metricsKey, $metrics, 86400);
        } catch (\Throwable) {
            // Metrics recording should never break the app
        }
    }

    /**
     * Get alert statistics for a given day.
     */
    public function getAlertStats(string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        return Cache::get("alert:metrics:{$date}", []);
    }
}
