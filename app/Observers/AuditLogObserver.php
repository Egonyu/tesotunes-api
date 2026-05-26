<?php

namespace App\Observers;

use App\Enums\Observability\EventOutcome;
use App\Enums\Observability\EventSeverity;
use App\Enums\Observability\SecurityEventType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Illuminate\Support\Str;

/**
 * Mirrors every audit-trail entry into the security console as a typed
 * {@see \App\Models\ObservabilityEvent}.
 *
 * `audit_logs` is already the chokepoint for payment lifecycle, webhook
 * processing, and privilege changes, so observing it gives the integrity and
 * payments domains real-time coverage without instrumenting dozens of callers.
 */
class AuditLogObserver
{
    public function created(AuditLog $auditLog): void
    {
        $action = (string) $auditLog->action;
        $type = $this->resolveType($action);
        $actor = $this->resolveActor($auditLog);

        $event = SecurityEvent::of($type)
            ->eventKey('audit:'.$auditLog->id)
            ->occurredAt($auditLog->created_at ?? now())
            ->summary(Str::headline($action).($auditLog->url ? ' — '.$auditLog->url : ''))
            ->actor($actor['type'], $actor['id'], $actor['label'])
            ->source($auditLog->ip_address, userAgent: $auditLog->user_agent)
            ->target(
                $auditLog->url,
                resourceType: $auditLog->auditable_type ? class_basename($auditLog->auditable_type) : null,
                resourceId: $auditLog->auditable_id,
            )
            ->correlation($auditLog->request_id, $auditLog->trace_id, $auditLog->session_id)
            ->details([
                'audit_action' => $action,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
            ])
            ->rawRef(['source' => 'audit_log', 'id' => $auditLog->id]);

        if ($outcome = $this->resolveOutcomeOverride($action)) {
            $event->outcome($outcome);
        }

        if ($this->isDestructive($action)) {
            $event->severity(EventSeverity::High);
        }

        SecurityEventRecorder::emit($event);
    }

    private function resolveType(string $action): SecurityEventType
    {
        $a = strtolower($action);

        return match (true) {
            str_contains($a, 'signature_failed'), str_contains($a, 'invalid_signature') => SecurityEventType::PaymentWebhookSignatureFailed,
            str_contains($a, 'replay') => SecurityEventType::PaymentWebhookReplayed,
            str_contains($a, 'webhook') => SecurityEventType::PaymentWebhookReceived,
            str_contains($a, 'refund') => SecurityEventType::PaymentRefundRequested,
            str_contains($a, 'payout') => SecurityEventType::PaymentPayoutRequested,
            str_contains($a, 'payment') && str_contains($a, 'fail') => SecurityEventType::PaymentFailed,
            str_contains($a, 'payment') => SecurityEventType::PaymentStatusChanged,
            str_contains($a, 'permission'), str_contains($a, 'role') => SecurityEventType::IntegrityPrivilegeChanged,
            str_contains($a, 'login') && (str_contains($a, 'fail') || str_contains($a, 'block')) => SecurityEventType::AuthLoginFailed,
            str_contains($a, 'login') => SecurityEventType::AuthLoginSucceeded,
            str_contains($a, 'logout') => SecurityEventType::AuthLogout,
            str_contains($a, 'setting'), str_contains($a, 'config') => SecurityEventType::IntegritySettingChanged,
            default => SecurityEventType::IntegrityAdminAction,
        };
    }

    private function resolveOutcomeOverride(string $action): ?EventOutcome
    {
        $a = strtolower($action);

        return match (true) {
            str_contains($a, 'replay'), str_contains($a, 'suspicious') => EventOutcome::Suspicious,
            str_contains($a, 'fail'),
            str_contains($a, 'not_found'),
            str_contains($a, 'missing'),
            str_contains($a, 'denied') => EventOutcome::Failed,
            default => null,
        };
    }

    private function isDestructive(string $action): bool
    {
        $a = strtolower($action);

        return str_contains($a, 'delete') || str_contains($a, 'destroy') || str_contains($a, 'purge');
    }

    /**
     * @return array{type: string, id: string|null, label: string|null}
     */
    private function resolveActor(AuditLog $auditLog): array
    {
        if (! $auditLog->user_id) {
            return ['type' => 'system', 'id' => null, 'label' => 'System'];
        }

        $user = User::query()
            ->select(['id', 'name', 'email', 'role'])
            ->find($auditLog->user_id);

        return [
            'type' => $user?->role ?: 'user',
            'id' => (string) $auditLog->user_id,
            'label' => $user?->name ?? $user?->email ?? ('User #'.$auditLog->user_id),
        ];
    }
}
