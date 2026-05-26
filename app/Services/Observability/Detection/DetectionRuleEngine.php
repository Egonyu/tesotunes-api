<?php

namespace App\Services\Observability\Detection;

use App\Models\Notification;
use App\Models\ObservabilityEvent;
use App\Models\ObservabilityIncident;
use App\Models\User;
use App\Services\Observability\Detection\Rules\BulkDeletionRule;
use App\Services\Observability\Detection\Rules\EndpointAbuseRule;
use App\Services\Observability\Detection\Rules\FailedLoginBurstRule;
use App\Services\Observability\Detection\Rules\WebhookSignatureFailureRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Runs every detection rule over the recent event stream and materializes
 * incident candidates into {@see ObservabilityIncident} records, linking the
 * driving events and alerting admins on newly opened high-risk incidents.
 */
class DetectionRuleEngine
{
    private const DEFAULT_WINDOW_MINUTES = 30;

    /** @var list<DetectionRule> */
    private readonly array $rules;

    public function __construct(
        FailedLoginBurstRule $failedLoginBurst,
        WebhookSignatureFailureRule $webhookSignatureFailure,
        EndpointAbuseRule $endpointAbuse,
        BulkDeletionRule $bulkDeletion,
    ) {
        $this->rules = [$failedLoginBurst, $webhookSignatureFailure, $endpointAbuse, $bulkDeletion];
    }

    /**
     * @return array{rules: int, incidents_opened: int, incidents_updated: int}
     */
    public function run(?Carbon $since = null): array
    {
        $since ??= Carbon::now()->subMinutes(self::DEFAULT_WINDOW_MINUTES);

        $opened = 0;
        $updated = 0;

        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($since) as $candidate) {
                $this->materialize($candidate) ? $opened++ : $updated++;
            }
        }

        return [
            'rules' => count($this->rules),
            'incidents_opened' => $opened,
            'incidents_updated' => $updated,
        ];
    }

    /**
     * @return bool True when a new incident was opened.
     */
    private function materialize(IncidentCandidate $candidate): bool
    {
        $incident = ObservabilityIncident::query()
            ->where('incident_key', $candidate->incidentKey())
            ->first();

        $isNew = $incident === null;

        if ($isNew) {
            $incident = new ObservabilityIncident([
                'incident_key' => $candidate->incidentKey(),
                'status' => 'open',
                'started_at' => Carbon::now(),
            ]);
        }

        $incident->fill([
            'title' => $candidate->title,
            'severity' => $candidate->severity->value,
            'summary' => $candidate->summary,
            'detected_at' => Carbon::now(),
            'metadata' => [
                'rule' => $candidate->ruleKey,
                'entity' => $candidate->entityKey,
                ...$candidate->metadata,
            ],
        ]);
        $incident->save();

        $incident->events()->syncWithoutDetaching($candidate->eventIds);

        ObservabilityEvent::query()
            ->whereIn('id', $candidate->eventIds)
            ->update(['incident_key' => $incident->incident_key]);

        if ($isNew && in_array($candidate->severity->value, ['high', 'critical'], true)) {
            $this->notifyAdmins($incident);
        }

        return $isNew;
    }

    private function notifyAdmins(ObservabilityIncident $incident): void
    {
        try {
            $admins = User::query()
                ->where(function ($query) {
                    $query->whereHas('roles', function ($roles) {
                        $roles->whereIn('name', ['admin', 'super_admin', 'super-admin']);
                    })->orWhereIn('role', ['admin', 'super_admin']);
                })
                ->get(['id']);

            foreach ($admins as $admin) {
                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'security_incident',
                    'category' => 'security',
                    'title' => 'Security incident: '.$incident->title,
                    'message' => $incident->summary ?? 'A security incident was detected.',
                    'action_url' => '/admin/observability',
                    'action_text' => 'Open security console',
                    'priority' => $incident->severity === 'critical' ? 'urgent' : 'high',
                    'data' => [
                        'incident_id' => $incident->id,
                        'incident_key' => $incident->incident_key,
                        'severity' => $incident->severity,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('observability.incident_notification_failed', [
                'incident_key' => $incident->incident_key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
