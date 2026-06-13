<?php

namespace App\Enums\Observability;

/**
 * Typed catalog of every security event the platform emits.
 *
 * This is the single source of truth for the observability taxonomy: it
 * replaces the old `str_contains()` heuristics in ObservabilityService. Each
 * case carries its domain, category, and sensible defaults for severity and
 * outcome — callers only override when the runtime context differs.
 */
enum SecurityEventType: string
{
    // ── Authentication & account takeover ────────────────────────────────
    case AuthLoginSucceeded = 'auth.login.succeeded';
    case AuthLoginFailed = 'auth.login.failed';
    case AuthLoginSuspicious = 'auth.login.suspicious';
    case AuthLockout = 'auth.lockout';
    case AuthLogout = 'auth.logout';
    case AuthRegistered = 'auth.registered';
    case AuthPasswordResetRequested = 'auth.password_reset.requested';
    case AuthPasswordChanged = 'auth.password.changed';
    case AuthTokenRefreshed = 'auth.token.refreshed';
    case AuthUnauthorized = 'auth.unauthorized';

    // ── Payments & fraud ─────────────────────────────────────────────────
    case PaymentWebhookReceived = 'payment.webhook.received';
    case PaymentWebhookSignatureFailed = 'payment.webhook.signature_failed';
    case PaymentWebhookReplayed = 'payment.webhook.replayed';
    case PaymentStatusChanged = 'payment.status.changed';
    case PaymentFailed = 'payment.failed';
    case PaymentRefundRequested = 'payment.refund.requested';
    case PaymentPayoutRequested = 'payment.payout.requested';

    // ── API abuse & bots ─────────────────────────────────────────────────
    case ApiRateLimitExceeded = 'api.rate_limit.exceeded';
    case ApiNotFoundScan = 'api.not_found.scan';
    case ApiForbiddenProbe = 'api.forbidden.probe';
    case ApiBotDetected = 'api.bot.detected';

    // ── Data integrity & insider ─────────────────────────────────────────
    case IntegrityAdminAction = 'integrity.admin.action';
    case IntegrityPrivilegeChanged = 'integrity.privilege.changed';
    case IntegritySettingChanged = 'integrity.setting.changed';
    case IntegrityBulkDeletion = 'integrity.bulk.deletion';
    case IntegritySensitiveFieldChanged = 'integrity.sensitive_field.changed';

    // ── Money pipeline & media ───────────────────────────────────────────
    case CommerceSettlementClearanceFailed = 'commerce.settlement.clearance_failed';
    case CommercePayoutReversed = 'commerce.payout.reversed';
    case MediaHlsTranscodeFailed = 'media.hls.transcode_failed';

    public function domain(): SecurityDomain
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLoginFailed,
            self::AuthLoginSuspicious,
            self::AuthLockout,
            self::AuthLogout,
            self::AuthRegistered,
            self::AuthPasswordResetRequested,
            self::AuthPasswordChanged,
            self::AuthTokenRefreshed,
            self::AuthUnauthorized => SecurityDomain::Auth,

            self::PaymentWebhookReceived,
            self::PaymentWebhookSignatureFailed,
            self::PaymentWebhookReplayed,
            self::PaymentStatusChanged,
            self::PaymentFailed,
            self::PaymentRefundRequested,
            self::PaymentPayoutRequested,
            self::CommerceSettlementClearanceFailed,
            self::CommercePayoutReversed => SecurityDomain::Payments,

            self::MediaHlsTranscodeFailed => SecurityDomain::System,

            self::ApiRateLimitExceeded,
            self::ApiNotFoundScan,
            self::ApiForbiddenProbe,
            self::ApiBotDetected => SecurityDomain::Api,

            self::IntegrityAdminAction,
            self::IntegrityPrivilegeChanged,
            self::IntegritySettingChanged,
            self::IntegrityBulkDeletion,
            self::IntegritySensitiveFieldChanged => SecurityDomain::Integrity,
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLoginFailed,
            self::AuthLoginSuspicious => 'login',
            self::AuthLockout => 'lockout',
            self::AuthLogout,
            self::AuthTokenRefreshed => 'session',
            self::AuthRegistered => 'registration',
            self::AuthPasswordResetRequested,
            self::AuthPasswordChanged => 'password',
            self::AuthUnauthorized,
            self::ApiForbiddenProbe => 'access',

            self::PaymentWebhookReceived,
            self::PaymentWebhookSignatureFailed,
            self::PaymentWebhookReplayed => 'webhook',
            self::PaymentStatusChanged,
            self::PaymentFailed => 'payment',
            self::PaymentRefundRequested => 'refund',
            self::PaymentPayoutRequested,
            self::CommercePayoutReversed => 'payout',
            self::CommerceSettlementClearanceFailed => 'settlement',
            self::MediaHlsTranscodeFailed => 'media',

            self::ApiRateLimitExceeded => 'rate_limit',
            self::ApiNotFoundScan => 'scan',
            self::ApiBotDetected => 'bot',

            self::IntegrityAdminAction,
            self::IntegrityBulkDeletion => 'change',
            self::IntegrityPrivilegeChanged,
            self::IntegritySensitiveFieldChanged => 'privilege',
            self::IntegritySettingChanged => 'config',
        };
    }

    public function defaultSeverity(): EventSeverity
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLogout,
            self::AuthRegistered,
            self::AuthTokenRefreshed,
            self::PaymentWebhookReceived,
            self::PaymentStatusChanged,
            self::IntegrityAdminAction => EventSeverity::Low,

            self::AuthLoginFailed,
            self::AuthPasswordResetRequested,
            self::AuthPasswordChanged,
            self::AuthUnauthorized,
            self::PaymentFailed,
            self::PaymentRefundRequested,
            self::PaymentPayoutRequested,
            self::CommercePayoutReversed,
            self::MediaHlsTranscodeFailed,
            self::ApiRateLimitExceeded,
            self::ApiNotFoundScan,
            self::ApiBotDetected,
            self::IntegritySettingChanged => EventSeverity::Medium,

            self::AuthLoginSuspicious,
            self::AuthLockout,
            self::PaymentWebhookSignatureFailed,
            self::PaymentWebhookReplayed,
            self::ApiForbiddenProbe,
            self::IntegrityPrivilegeChanged,
            self::IntegrityBulkDeletion,
            self::IntegritySensitiveFieldChanged,
            self::CommerceSettlementClearanceFailed => EventSeverity::High,
        };
    }

    public function defaultOutcome(): EventOutcome
    {
        return match ($this) {
            self::AuthLoginSucceeded,
            self::AuthLogout,
            self::AuthRegistered,
            self::AuthPasswordResetRequested,
            self::AuthPasswordChanged,
            self::AuthTokenRefreshed,
            self::PaymentWebhookReceived,
            self::PaymentStatusChanged,
            self::PaymentRefundRequested,
            self::PaymentPayoutRequested,
            self::IntegrityAdminAction,
            self::IntegrityPrivilegeChanged,
            self::IntegritySettingChanged,
            self::IntegrityBulkDeletion,
            self::IntegritySensitiveFieldChanged => EventOutcome::Success,

            self::AuthLoginFailed,
            self::PaymentWebhookSignatureFailed,
            self::PaymentFailed,
            self::CommerceSettlementClearanceFailed,
            self::MediaHlsTranscodeFailed => EventOutcome::Failed,

            self::AuthLockout,
            self::AuthUnauthorized,
            self::ApiRateLimitExceeded,
            self::ApiForbiddenProbe => EventOutcome::Blocked,

            self::AuthLoginSuspicious,
            self::PaymentWebhookReplayed,
            self::CommercePayoutReversed,
            self::ApiNotFoundScan,
            self::ApiBotDetected => EventOutcome::Suspicious,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::AuthLoginSucceeded => 'Login succeeded',
            self::AuthLoginFailed => 'Login failed',
            self::AuthLoginSuspicious => 'Suspicious login',
            self::AuthLockout => 'Account lockout triggered',
            self::AuthLogout => 'Logout',
            self::AuthRegistered => 'New account registered',
            self::AuthPasswordResetRequested => 'Password reset requested',
            self::AuthPasswordChanged => 'Password changed',
            self::AuthTokenRefreshed => 'Access token refreshed',
            self::AuthUnauthorized => 'Unauthorized request',
            self::PaymentWebhookReceived => 'Payment webhook received',
            self::PaymentWebhookSignatureFailed => 'Webhook signature verification failed',
            self::PaymentWebhookReplayed => 'Replayed payment webhook',
            self::PaymentStatusChanged => 'Payment status changed',
            self::PaymentFailed => 'Payment failed',
            self::PaymentRefundRequested => 'Refund requested',
            self::PaymentPayoutRequested => 'Payout requested',
            self::ApiRateLimitExceeded => 'Rate limit exceeded',
            self::ApiNotFoundScan => 'Endpoint scanning detected',
            self::ApiForbiddenProbe => 'Forbidden endpoint probed',
            self::ApiBotDetected => 'Automated client detected',
            self::IntegrityAdminAction => 'Administrative change',
            self::IntegrityPrivilegeChanged => 'Privilege or role changed',
            self::IntegritySettingChanged => 'Platform setting changed',
            self::IntegrityBulkDeletion => 'Bulk deletion performed',
            self::IntegritySensitiveFieldChanged => 'Sensitive field modified',
            self::CommerceSettlementClearanceFailed => 'Settlement clearance failed',
            self::CommercePayoutReversed => 'Settlement reversed',
            self::MediaHlsTranscodeFailed => 'HLS transcode failed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
