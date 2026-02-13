<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Security Settings Service
 * 
 * Handles all business logic related to platform security settings.
 * This service centralizes security configuration management and provides
 * reusable methods for security-related business rules.
 */
class SecuritySettingsService
{
    /**
     * Get all security-related settings.
     * 
     * @return array
     */
    public function getSettings(): array
    {
        return [
            // Authentication settings
            'require_2fa_admins' => Setting::get('security_require_2fa_admins', false),
            'enable_session_timeout' => Setting::get('security_enable_session_timeout', true),
            'session_timeout_minutes' => Setting::get('security_session_timeout_minutes', 30),
            'max_login_attempts' => Setting::get('security_max_login_attempts', 5),
            'login_lockout_duration' => Setting::get('security_login_lockout_duration', 15),
            
            // Security logging
            'log_security_events' => Setting::get('security_log_events', true),
            'log_failed_logins' => Setting::get('security_log_failed_logins', true),
            'log_password_changes' => Setting::get('security_log_password_changes', true),
            'log_permission_changes' => Setting::get('security_log_permission_changes', true),
            
            // Password policy
            'password_min_length' => Setting::get('security_password_min_length', 8),
            'password_require_uppercase' => Setting::get('security_password_require_uppercase', true),
            'password_require_lowercase' => Setting::get('security_password_require_lowercase', true),
            'password_require_numbers' => Setting::get('security_password_require_numbers', true),
            'password_require_symbols' => Setting::get('security_password_require_symbols', false),
            'password_expiry_days' => Setting::get('security_password_expiry_days', 90),
            'password_history_count' => Setting::get('security_password_history_count', 5),
            
            // IP & Rate limiting
            'enable_ip_whitelist' => Setting::get('security_enable_ip_whitelist', false),
            'enable_ip_blacklist' => Setting::get('security_enable_ip_blacklist', true),
            'rate_limit_enabled' => Setting::get('security_rate_limit_enabled', true),
            'rate_limit_requests' => Setting::get('security_rate_limit_requests', 60),
            'rate_limit_period' => Setting::get('security_rate_limit_period', 1),
        ];
    }

    /**
     * Update security settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateSettings(array $data): bool
    {
        try {
            $settings = [
                // Authentication
                'security_require_2fa_admins' => $data['require_2fa_admins'] ?? false,
                'security_enable_session_timeout' => $data['enable_session_timeout'] ?? true,
                'security_session_timeout_minutes' => (int) ($data['session_timeout_minutes'] ?? 30),
                'security_max_login_attempts' => (int) ($data['max_login_attempts'] ?? 5),
                'security_login_lockout_duration' => (int) ($data['login_lockout_duration'] ?? 15),
                
                // Logging
                'security_log_events' => $data['log_security_events'] ?? true,
                'security_log_failed_logins' => $data['log_failed_logins'] ?? true,
                'security_log_password_changes' => $data['log_password_changes'] ?? true,
                'security_log_permission_changes' => $data['log_permission_changes'] ?? true,
            ];

            // Validate session timeout
            if ($settings['security_session_timeout_minutes'] < 5 || $settings['security_session_timeout_minutes'] > 480) {
                Log::warning('Invalid session timeout value', ['value' => $settings['security_session_timeout_minutes']]);
                return false;
            }

            // Validate max login attempts
            if ($settings['security_max_login_attempts'] < 1 || $settings['security_max_login_attempts'] > 20) {
                Log::warning('Invalid max login attempts value', ['value' => $settings['security_max_login_attempts']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_SECURITY);
            }

            Log::info('Security settings updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update security settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update password policy settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updatePasswordPolicy(array $data): bool
    {
        try {
            $settings = [
                'security_password_min_length' => (int) ($data['password_min_length'] ?? 8),
                'security_password_require_uppercase' => $data['password_require_uppercase'] ?? true,
                'security_password_require_lowercase' => $data['password_require_lowercase'] ?? true,
                'security_password_require_numbers' => $data['password_require_numbers'] ?? true,
                'security_password_require_symbols' => $data['password_require_symbols'] ?? false,
                'security_password_expiry_days' => (int) ($data['password_expiry_days'] ?? 90),
                'security_password_history_count' => (int) ($data['password_history_count'] ?? 5),
            ];

            // Validate password length
            if ($settings['security_password_min_length'] < 6 || $settings['security_password_min_length'] > 128) {
                Log::warning('Invalid password min length', ['value' => $settings['security_password_min_length']]);
                return false;
            }

            // Validate password expiry
            if ($settings['security_password_expiry_days'] < 0 || $settings['security_password_expiry_days'] > 365) {
                Log::warning('Invalid password expiry days', ['value' => $settings['security_password_expiry_days']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_SECURITY);
            }

            Log::info('Password policy updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update password policy', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update IP and rate limiting settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateIpAndRateLimiting(array $data): bool
    {
        try {
            $settings = [
                'security_enable_ip_whitelist' => $data['enable_ip_whitelist'] ?? false,
                'security_enable_ip_blacklist' => $data['enable_ip_blacklist'] ?? true,
                'security_rate_limit_enabled' => $data['rate_limit_enabled'] ?? true,
                'security_rate_limit_requests' => (int) ($data['rate_limit_requests'] ?? 60),
                'security_rate_limit_period' => (int) ($data['rate_limit_period'] ?? 1),
            ];

            // Validate rate limit requests
            if ($settings['security_rate_limit_requests'] < 1 || $settings['security_rate_limit_requests'] > 1000) {
                Log::warning('Invalid rate limit requests', ['value' => $settings['security_rate_limit_requests']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_SECURITY);
            }

            Log::info('IP and rate limiting settings updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update IP and rate limiting settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // ==================== Business Logic Methods ====================

    /**
     * Check if 2FA is required for admins.
     * 
     * @return bool
     */
    public function isTwoFactorRequiredForAdmins(): bool
    {
        return Setting::get('security_require_2fa_admins', false);
    }

    /**
     * Check if session timeout is enabled.
     * 
     * @return bool
     */
    public function isSessionTimeoutEnabled(): bool
    {
        return Setting::get('security_enable_session_timeout', true);
    }

    /**
     * Get session timeout duration in minutes.
     * 
     * @return int
     */
    public function getSessionTimeoutMinutes(): int
    {
        return Setting::get('security_session_timeout_minutes', 30);
    }

    /**
     * Get maximum login attempts allowed.
     * 
     * @return int
     */
    public function getMaxLoginAttempts(): int
    {
        return Setting::get('security_max_login_attempts', 5);
    }

    /**
     * Get login lockout duration in minutes.
     * 
     * @return int
     */
    public function getLoginLockoutDuration(): int
    {
        return Setting::get('security_login_lockout_duration', 15);
    }

    /**
     * Check if security event logging is enabled.
     * 
     * @return bool
     */
    public function isSecurityLoggingEnabled(): bool
    {
        return Setting::get('security_log_events', true);
    }

    /**
     * Check if failed login logging is enabled.
     * 
     * @return bool
     */
    public function isFailedLoginLoggingEnabled(): bool
    {
        return Setting::get('security_log_failed_logins', true);
    }

    /**
     * Get minimum password length.
     * 
     * @return int
     */
    public function getPasswordMinLength(): int
    {
        return Setting::get('security_password_min_length', 8);
    }

    /**
     * Get password policy requirements.
     * 
     * @return array
     */
    public function getPasswordRequirements(): array
    {
        return [
            'min_length' => $this->getPasswordMinLength(),
            'require_uppercase' => Setting::get('security_password_require_uppercase', true),
            'require_lowercase' => Setting::get('security_password_require_lowercase', true),
            'require_numbers' => Setting::get('security_password_require_numbers', true),
            'require_symbols' => Setting::get('security_password_require_symbols', false),
        ];
    }

    /**
     * Validate password against security policy.
     * 
     * @param string $password
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validatePassword(string $password): array
    {
        $requirements = $this->getPasswordRequirements();
        $errors = [];

        // Check minimum length
        if (strlen($password) < $requirements['min_length']) {
            $errors[] = "Password must be at least {$requirements['min_length']} characters long";
        }

        // Check uppercase
        if ($requirements['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        // Check lowercase
        if ($requirements['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        // Check numbers
        if ($requirements['require_numbers'] && !preg_match('/\d/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        // Check symbols
        if ($requirements['require_symbols'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check if user account is locked due to failed login attempts.
     * 
     * @param string $identifier (email or username)
     * @return bool
     */
    public function isAccountLocked(string $identifier): bool
    {
        $lockoutDuration = $this->getLoginLockoutDuration();
        $maxAttempts = $this->getMaxLoginAttempts();

        // Check failed login attempts in the last lockout period
        $failedAttempts = DB::table('failed_login_attempts')
            ->where('identifier', $identifier)
            ->where('attempted_at', '>=', now()->subMinutes($lockoutDuration))
            ->count();

        return $failedAttempts >= $maxAttempts;
    }

    /**
     * Record a failed login attempt.
     * 
     * @param string $identifier
     * @param string $ipAddress
     * @return void
     */
    public function recordFailedLoginAttempt(string $identifier, string $ipAddress): void
    {
        if ($this->isFailedLoginLoggingEnabled()) {
            DB::table('failed_login_attempts')->insert([
                'identifier' => $identifier,
                'ip_address' => $ipAddress,
                'user_agent' => request()->userAgent(),
                'attempted_at' => now(),
            ]);

            Log::channel('security')->warning('Failed login attempt', [
                'identifier' => $identifier,
                'ip_address' => $ipAddress,
            ]);
        }
    }

    /**
     * Clear failed login attempts for a user (after successful login).
     * 
     * @param string $identifier
     * @return void
     */
    public function clearFailedLoginAttempts(string $identifier): void
    {
        DB::table('failed_login_attempts')
            ->where('identifier', $identifier)
            ->delete();
    }

    /**
     * Get remaining login attempts for an identifier.
     * 
     * @param string $identifier
     * @return int
     */
    public function getRemainingLoginAttempts(string $identifier): int
    {
        $lockoutDuration = $this->getLoginLockoutDuration();
        $maxAttempts = $this->getMaxLoginAttempts();

        $failedAttempts = DB::table('failed_login_attempts')
            ->where('identifier', $identifier)
            ->where('attempted_at', '>=', now()->subMinutes($lockoutDuration))
            ->count();

        return max(0, $maxAttempts - $failedAttempts);
    }

    /**
     * Log a security event if logging is enabled.
     * 
     * @param string $event
     * @param array $context
     * @return void
     */
    public function logSecurityEvent(string $event, array $context = []): void
    {
        if ($this->isSecurityLoggingEnabled()) {
            Log::channel('security')->info($event, array_merge([
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now(),
            ], $context));
        }
    }

    /**
     * Check if IP is blacklisted.
     * 
     * @param string $ipAddress
     * @return bool
     */
    public function isIpBlacklisted(string $ipAddress): bool
    {
        if (!Setting::get('security_enable_ip_blacklist', true)) {
            return false;
        }

        return DB::table('ip_blacklist')
            ->where('ip_address', $ipAddress)
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Check if IP is whitelisted.
     * 
     * @param string $ipAddress
     * @return bool
     */
    public function isIpWhitelisted(string $ipAddress): bool
    {
        if (!Setting::get('security_enable_ip_whitelist', false)) {
            return true; // If whitelist is disabled, all IPs are allowed
        }

        return DB::table('ip_whitelist')
            ->where('ip_address', $ipAddress)
            ->exists();
    }

    /**
     * Check if request should be rate limited.
     * 
     * @param string $key (user ID, IP, etc.)
     * @param string $action
     * @return bool
     */
    public function shouldRateLimit(string $key, string $action = 'general'): bool
    {
        if (!Setting::get('security_rate_limit_enabled', true)) {
            return false;
        }

        $maxRequests = Setting::get('security_rate_limit_requests', 60);
        $periodMinutes = Setting::get('security_rate_limit_period', 1);

        $cacheKey = "rate_limit:{$action}:{$key}";
        
        $requests = cache()->get($cacheKey, 0);
        
        if ($requests >= $maxRequests) {
            return true; // Should be rate limited
        }

        cache()->put($cacheKey, $requests + 1, now()->addMinutes($periodMinutes));
        
        return false;
    }

    /**
     * Get security audit log for a user.
     * 
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getUserSecurityAuditLog(int $userId, int $limit = 50)
    {
        return DB::table('security_audit_log')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if user requires 2FA based on their role.
     * 
     * @param User $user
     * @return bool
     */
    public function userRequires2FA(User $user): bool
    {
        if ($this->isTwoFactorRequiredForAdmins()) {
            return in_array($user->role, ['admin', 'super_admin', 'moderator', 'finance']);
        }

        return false;
    }

    /**
     * Get security statistics.
     * 
     * @return array
     */
    public function getSecurityStatistics(): array
    {
        $lockoutDuration = $this->getLoginLockoutDuration();

        return [
            'total_failed_logins_today' => DB::table('failed_login_attempts')
                ->whereDate('attempted_at', today())
                ->count(),
            'total_failed_logins_this_hour' => DB::table('failed_login_attempts')
                ->where('attempted_at', '>=', now()->subHour())
                ->count(),
            'locked_accounts' => DB::table('failed_login_attempts')
                ->select('identifier')
                ->where('attempted_at', '>=', now()->subMinutes($lockoutDuration))
                ->groupBy('identifier')
                ->havingRaw('COUNT(*) >= ?', [$this->getMaxLoginAttempts()])
                ->count(),
            'blacklisted_ips' => DB::table('ip_blacklist')
                ->where(function($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->count(),
            'users_with_2fa' => User::whereNotNull('two_factor_secret')->count(),
        ];
    }
}
