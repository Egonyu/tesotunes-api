<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecurityMonitoringService
{
    /**
     * Log security event
     */
    public function logSecurityEvent(string $type, array $data, string $severity = 'info'): void
    {
        $logData = array_merge([
            'timestamp' => now()->toISOString(),
            'type' => $type,
            'severity' => $severity,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'user_id' => auth()->id(),
            'session_id' => session()->getId(),
        ], $data);

        // Log to Laravel log
        Log::channel('security')->{$severity}("Security Event: {$type}", $logData);

        // Store in database for analysis
        $this->storeSecurityEvent($logData);

        // Check for suspicious patterns
        $this->checkSuspiciousActivity($type, $logData);
    }

    /**
     * Log authentication attempt
     */
    public function logAuthenticationAttempt(string $email, bool $successful, array $additional = []): void
    {
        $this->logSecurityEvent('authentication_attempt', array_merge([
            'email' => $email,
            'successful' => $successful,
            'method' => 'web_login',
        ], $additional), $successful ? 'info' : 'warning');
    }

    /**
     * Log API access attempt
     */
    public function logApiAccess(Request $request, bool $authorized): void
    {
        $this->logSecurityEvent('api_access', [
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'authorized' => $authorized,
            'token_used' => $request->bearerToken() ? 'yes' : 'no',
        ], $authorized ? 'info' : 'warning');
    }

    /**
     * Log file upload attempt
     */
    public function logFileUpload(string $filename, bool $secure, array $validationErrors = []): void
    {
        $this->logSecurityEvent('file_upload', [
            'filename' => $filename,
            'secure' => $secure,
            'validation_errors' => $validationErrors,
        ], $secure ? 'info' : 'warning');
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(string $activity, array $details): void
    {
        $this->logSecurityEvent('suspicious_activity', array_merge([
            'activity' => $activity,
        ], $details), 'warning');

        // Alert administrators if needed
        $this->alertAdministrators($activity, $details);
    }

    /**
     * Store security event in database
     */
    private function storeSecurityEvent(array $data): void
    {
        try {
            $severityScore = match($data['severity'] ?? 'info') {
                'critical' => 100,
                'error' => 75,
                'warning' => 50,
                'info' => 25,
                default => 10,
            };
            
            // Map type to valid event_type enum value
            $eventType = match($data['type'] ?? 'unknown') {
                'authentication_attempt' => $data['successful'] ?? false ? 'login_success' : 'login_failure',
                'login' => 'login_attempt',
                'logout' => 'logout',
                'password_change' => 'password_change',
                'password_reset' => 'password_reset',
                'api_access' => 'api_key_used',
                'rate_limit' => 'rate_limit_exceeded',
                'brute_force' => 'brute_force_detected',
                'suspicious_activity' => 'suspicious_activity',
                'permission_violation' => 'permission_violation',
                default => 'suspicious_activity',
            };
            
            DB::table('security_logs')->insert([
                'trace_id' => (string) \Illuminate\Support\Str::uuid(),
                'audit_log_id' => 0,
                'event_type' => $eventType,
                'ip_address' => $data['ip'] ?? request()->ip(),
                'user_agent' => substr($data['user_agent'] ?? request()->userAgent() ?? '', 0, 255),
                'user_id' => $data['user_id'] ?? null,
                'is_suspicious' => in_array($data['severity'] ?? '', ['warning', 'error', 'critical']),
                'risk_score' => $severityScore,
                'threat_indicators' => json_encode([$data['type'] ?? 'unknown']),
                'narrative' => "Security Event: " . ($data['type'] ?? 'unknown'),
                'metadata' => json_encode($data),
                'is_blocked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store security event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check for suspicious activity patterns
     */
    private function checkSuspiciousActivity(string $type, array $data): void
    {
        $ip = $data['ip'];
        $userId = $data['user_id'];

        // Check for brute force attacks
        if ($type === 'authentication_attempt' && !$data['successful']) {
            $this->checkBruteForce($ip, $data['email']);
        }

        // Check for excessive API calls
        if ($type === 'api_access') {
            $this->checkApiAbuse($ip, $userId);
        }

        // Check for mass file uploads
        if ($type === 'file_upload') {
            $this->checkUploadAbuse($ip, $userId);
        }
    }

    /**
     * Check for brute force login attempts
     */
    private function checkBruteForce(string $ip, string $email): void
    {
        $key = "failed_login_{$ip}";
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes(15));

        if ($attempts >= 5) {
            $this->logSuspiciousActivity('brute_force_attempt', [
                'ip' => $ip,
                'email' => $email,
                'attempts' => $attempts,
            ]);

            // Block IP for 1 hour
            Cache::put("blocked_ip_{$ip}", true, now()->addHour());
        }
    }

    /**
     * Check for API abuse
     */
    private function checkApiAbuse(string $ip, ?int $userId): void
    {
        $key = "api_calls_{$ip}";
        $calls = Cache::get($key, 0) + 1;

        Cache::put($key, $calls, now()->addMinute());

        if ($calls > 100) { // More than 100 calls per minute
            $this->logSuspiciousActivity('api_abuse', [
                'ip' => $ip,
                'user_id' => $userId,
                'calls_per_minute' => $calls,
            ]);
        }
    }

    /**
     * Check for upload abuse
     */
    private function checkUploadAbuse(string $ip, ?int $userId): void
    {
        $key = "uploads_{$ip}";
        $uploads = Cache::get($key, 0) + 1;

        Cache::put($key, $uploads, now()->addHour());

        if ($uploads > 20) { // More than 20 uploads per hour
            $this->logSuspiciousActivity('upload_abuse', [
                'ip' => $ip,
                'user_id' => $userId,
                'uploads_per_hour' => $uploads,
            ]);
        }
    }

    /**
     * Alert administrators about security issues
     */
    private function alertAdministrators(string $activity, array $details): void
    {
        // This could send emails, Slack notifications, etc.
        Log::critical("Security Alert: {$activity}", $details);

        // Store alert for admin dashboard
        Cache::tags(['security_alerts'])->put(
            "alert_" . now()->timestamp,
            [
                'activity' => $activity,
                'details' => $details,
                'timestamp' => now(),
            ],
            now()->addDays(7)
        );
    }

    /**
     * Get recent security alerts
     */
    public function getRecentAlerts(int $limit = 10): array
    {
        try {
            $alerts = [];
            $cachedAlerts = Cache::tags(['security_alerts'])->get('recent_alerts', []);

            return array_slice($cachedAlerts, 0, $limit);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve security alerts', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Check if IP is blocked
     */
    public function isIpBlocked(string $ip): bool
    {
        return Cache::has("blocked_ip_{$ip}");
    }

    /**
     * Generate security dashboard data
     */
    public function getDashboardData(): array
    {
        try {
            $data = [
                'total_events_today' => $this->getEventCount('today'),
                'failed_logins_today' => $this->getFailedLoginCount('today'),
                'api_calls_today' => $this->getApiCallCount('today'),
                'uploads_today' => $this->getUploadCount('today'),
                'blocked_ips' => $this->getBlockedIpsCount(),
                'recent_alerts' => $this->getRecentAlerts(5),
            ];

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to generate security dashboard data', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get event count for period
     */
    private function getEventCount(string $period): int
    {
        $startDate = $period === 'today' ? now()->startOfDay() : now()->subDays(7);

        return DB::table('security_logs')
            ->where('created_at', '>=', $startDate)
            ->count();
    }

    /**
     * Get failed login count
     */
    private function getFailedLoginCount(string $period): int
    {
        $startDate = $period === 'today' ? now()->startOfDay() : now()->subDays(7);

        return DB::table('security_logs')
            ->where('following_type', 'authentication_attempt')
            ->where('created_at', '>=', $startDate)
            ->whereJsonContains('data->successful', false)
            ->count();
    }

    /**
     * Get API call count
     */
    private function getApiCallCount(string $period): int
    {
        $startDate = $period === 'today' ? now()->startOfDay() : now()->subDays(7);

        return DB::table('security_logs')
            ->where('following_type', 'api_access')
            ->where('created_at', '>=', $startDate)
            ->count();
    }

    /**
     * Get upload count
     */
    private function getUploadCount(string $period): int
    {
        $startDate = $period === 'today' ? now()->startOfDay() : now()->subDays(7);

        return DB::table('security_logs')
            ->where('following_type', 'file_upload')
            ->where('created_at', '>=', $startDate)
            ->count();
    }

    /**
     * Get blocked IPs count
     */
    private function getBlockedIpsCount(): int
    {
        // This would need to be implemented based on your caching strategy
        return 0;
    }
}