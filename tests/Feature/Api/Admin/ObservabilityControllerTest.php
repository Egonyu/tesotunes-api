<?php

namespace Tests\Feature\Api\Admin;

use App\Models\ApiUsageLog;
use App\Models\AuditLog;
use App\Models\ObservabilityEvent;
use App\Models\ObservabilityRollupHourly;
use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ObservabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate from observability_events created by other suites via
        // the AuditLogObserver / api-group MonitorApiAbuse middleware.
        ObservabilityEvent::query()->delete();
    }

    public function test_admin_can_view_phase_one_observability_feeds(): void
    {
        config()->set('services.observability.collector_token', 'collector-secret');

        $admin = User::factory()->create();
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );

        $admin->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $customer = User::factory()->create();

        AuditLog::withoutEvents(fn () => AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'login_failed',
            'ip_address' => '198.51.100.42',
            'user_agent' => 'SecurityTest/1.0',
            'url' => '/api/login',
            'request_id' => 'req-test-1',
            'trace_id' => 'trace-test-1',
            'session_id' => 'sess-test-1',
            'new_values' => ['reason' => 'bad_password'],
        ]));

        AuditLog::withoutEvents(fn () => AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'login_failed',
            'ip_address' => '198.51.100.42',
            'user_agent' => 'SecurityTest/1.0',
            'url' => '/api/login',
            'request_id' => 'req-test-1b',
            'trace_id' => 'trace-test-1b',
            'session_id' => 'sess-test-1b',
            'new_values' => ['reason' => 'bad_password'],
        ]));

        ApiUsageLog::create([
            'user_id' => null,
            'method' => 'GET',
            'endpoint' => '/api/admin/settings',
            'status_code' => 403,
            'response_time_ms' => 120,
            'ip_address' => '198.51.100.42',
            'user_agent' => 'SecurityTest/1.0',
            'requested_at' => now(),
            'request_id' => 'req-test-2',
            'trace_id' => 'trace-test-2',
            'session_id' => 'sess-test-2',
        ]);

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $customer->id,
            'payment_type' => 'wallet_topup',
            'status' => Payment::STATUS_COMPLETED,
            'payment_reference' => 'OBS-RISK-001',
            'transaction_reference' => 'OBS-RISK-001',
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
        ]));

        PaymentIssue::create([
            'payment_id' => $payment->id,
            'issue_type' => PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
            'title' => 'Invalid webhook signature',
            'status' => PaymentIssue::STATUS_OPEN,
            'severity' => 'critical',
            'metadata' => [
                'ip_address' => '203.0.113.99',
                'request_id' => 'req-test-3',
                'trace_id' => 'trace-test-3',
                'session_id' => 'sess-test-3',
            ],
        ]);

        AuditLog::withoutEvents(fn () => AuditLog::create([
            'user_id' => $customer->id,
            'action' => 'payment_webhook_signature_failed',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'ip_address' => '203.0.113.99',
            'user_agent' => 'WebhookTest/1.0',
            'url' => '/api/webhooks/zengapay',
            'request_id' => 'req-test-4',
            'trace_id' => 'trace-test-4',
            'session_id' => 'sess-test-4',
            'new_values' => [
                'provider' => 'zengapay',
                'status' => 'unknown',
            ],
        ]));

        Cache::put('performance:slow_queries:'.now()->format('Y-m-d'), [[
            'query' => 'select * from payments where status = ?',
            'time' => 420,
            'timestamp' => now()->toIso8601String(),
        ]], 86400);

        ObservabilityEvent::query()->create([
            'event_key' => 'test:suggestion:1',
            'source_type' => 'test',
            'source_id' => 's1',
            'occurred_at' => now()->subMinutes(5),
            'domain' => 'auth',
            'category' => 'auth',
            'outcome' => 'failed',
            'severity' => 'high',
            'title' => 'Login Failed',
            'summary' => 'Credential attack',
            'source_ip' => '198.51.100.55',
            'target_route' => '/api/login',
            'attack_pattern' => 'burst',
            'risk_score' => 82,
            'risk_reasons' => ['repeat-velocity'],
            'details' => [],
            'raw_ref' => ['source' => 'test'],
            'linked_entity_keys' => [],
            'environment' => 'testing',
        ]);

        ObservabilityEvent::query()->create([
            'event_key' => 'test:old:1',
            'source_type' => 'test',
            'source_id' => 'old-1',
            'occurred_at' => now()->subDays(400),
            'domain' => 'system',
            'category' => 'system',
            'outcome' => 'suspicious',
            'severity' => 'medium',
            'title' => 'Old retained event',
            'summary' => 'Old event for pruning checks',
            'source_ip' => '192.0.2.10',
            'host' => 'legacy-host',
            'risk_score' => 55,
            'risk_reasons' => ['retention-check'],
            'details' => [],
            'raw_ref' => ['source' => 'test'],
            'linked_entity_keys' => [],
            'environment' => 'testing',
        ]);

        ObservabilityEvent::query()->create([
            'event_key' => 'test:suggestion:2',
            'source_type' => 'test',
            'source_id' => 's2',
            'occurred_at' => now()->subMinutes(4),
            'domain' => 'auth',
            'category' => 'auth',
            'outcome' => 'failed',
            'severity' => 'high',
            'title' => 'Login Failed',
            'summary' => 'Credential attack',
            'source_ip' => '198.51.100.55',
            'target_route' => '/api/login',
            'attack_pattern' => 'burst',
            'risk_score' => 80,
            'risk_reasons' => ['repeat-velocity'],
            'details' => [],
            'raw_ref' => ['source' => 'test'],
            'linked_entity_keys' => [],
            'environment' => 'testing',
        ]);

        $this->postJson('/api/observability/collector/events', [
            'events' => [[
                'event_key' => 'collector:host:1',
                'occurred_at' => now()->subMinutes(20)->toIso8601String(),
                'domain' => 'system',
                'category' => 'system',
                'outcome' => 'suspicious',
                'severity' => 'high',
                'title' => 'SSH failure burst',
                'summary' => 'Collector detected repeated SSH failures on prod host.',
                'source' => [
                    'ip' => '203.0.113.11',
                    'country' => 'UG',
                    'asn' => 'AS64500',
                ],
                'actor' => [
                    'type' => 'service',
                    'id' => 'collector-agent',
                    'label' => 'collector-agent',
                ],
                'target' => [
                    'route' => 'ssh://prod-web-1',
                    'resource_type' => 'host',
                    'resource_id' => 'prod-web-1',
                ],
                'infra' => [
                    'host' => 'prod-web-1',
                    'environment' => 'production',
                ],
                'correlation' => [
                    'session_id' => 'collector-session-1',
                ],
                'details' => [
                    'payment_reference' => 'OBS-COLLECT-001',
                    'signal_type' => 'ssh_failures',
                ],
                'raw_ref' => [
                    'source' => 'collector',
                    'stream' => 'ssh',
                ],
            ], [
                'event_key' => 'collector:db:1',
                'occurred_at' => now()->subSeconds(10)->toIso8601String(),
                'domain' => 'db',
                'category' => 'db',
                'outcome' => 'suspicious',
                'severity' => 'critical',
                'title' => 'Database auth failures',
                'summary' => 'Collector detected repeated database authentication failures.',
                'source' => [
                    'ip' => '203.0.113.11',
                ],
                'target' => [
                    'route' => 'postgres://prod-db-1',
                    'resource_type' => 'database',
                    'resource_id' => 'prod-db-1',
                ],
                'infra' => [
                    'host' => 'prod-db-1',
                    'environment' => 'production',
                ],
                'correlation' => [
                    'session_id' => 'collector-session-1',
                ],
                'details' => [
                    'payment_reference' => 'OBS-COLLECT-001',
                    'query_class' => 'auth_failure',
                ],
                'raw_ref' => [
                    'source' => 'collector',
                    'stream' => 'database',
                ],
            ], [
                'event_key' => 'collector:db:2',
                'occurred_at' => now()->subSeconds(8)->toIso8601String(),
                'domain' => 'db',
                'category' => 'db',
                'outcome' => 'suspicious',
                'severity' => 'high',
                'title' => 'Privileged write burst',
                'summary' => 'Collector detected unexpected privileged writes on prod DB.',
                'source' => [
                    'ip' => '203.0.113.11',
                ],
                'target' => [
                    'route' => 'postgres://prod-db-1',
                    'resource_type' => 'database',
                    'resource_id' => 'prod-db-1',
                ],
                'infra' => [
                    'host' => 'prod-db-1',
                    'environment' => 'production',
                ],
                'details' => [
                    'payment_reference' => 'OBS-COLLECT-001',
                    'signal_type' => 'privileged_write',
                ],
                'raw_ref' => [
                    'source' => 'collector',
                    'stream' => 'database',
                ],
            ], [
                'event_key' => 'collector:db:3',
                'occurred_at' => now()->subSeconds(6)->toIso8601String(),
                'domain' => 'db',
                'category' => 'db',
                'outcome' => 'suspicious',
                'severity' => 'critical',
                'title' => 'Destructive query detected',
                'summary' => 'Collector detected destructive query activity on prod DB.',
                'source' => [
                    'ip' => '203.0.113.11',
                ],
                'target' => [
                    'route' => 'postgres://prod-db-1',
                    'resource_type' => 'database',
                    'resource_id' => 'prod-db-1',
                ],
                'infra' => [
                    'host' => 'prod-db-1',
                    'environment' => 'production',
                ],
                'details' => [
                    'payment_reference' => 'OBS-COLLECT-001',
                    'signal_type' => 'drop table',
                ],
                'raw_ref' => [
                    'source' => 'collector',
                    'stream' => 'database',
                ],
            ]],
        ], [
            'X-Observability-Token' => 'collector-secret',
        ])->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ingested', 4);

        $this->postJson('/api/observability/collector/events', [
            'events' => [[
                'occurred_at' => now()->toIso8601String(),
                'domain' => 'system',
                'category' => 'system',
                'outcome' => 'suspicious',
                'severity' => 'high',
                'title' => 'Blocked',
                'summary' => 'Blocked',
            ]],
        ])->assertForbidden();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.collector_stale_sources', 1)
            ->assertJsonPath('data.summary.collector_reporting_hosts', 2)
            ->assertJsonPath('data.summary.collector_telemetry_gaps', 3)
            ->assertJsonPath('data.summary.critical_system_signals', 1)
            ->assertJsonPath('data.summary.db_auth_failures', 1)
            ->assertJsonPath('data.summary.db_privileged_writes', 1)
            ->assertJsonPath('data.summary.db_destructive_queries', 1)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'active_threats',
                        'suspicious_successes',
                        'bot_pressure',
                        'payment_risk_events',
                        'db_anomalies',
                        'unresolved_incidents',
                    ],
                    'recent_events',
                ],
            ]);

        $pivotCountAfterFirstSync = DB::table('observability_event_entities')->count();

        $this->getJson('/api/admin/observability/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.collector_reporting_hosts', 2);

        $this->assertSame(
            $pivotCountAfterFirstSync,
            DB::table('observability_event_entities')->count(),
            'Repeated observability syncs should not duplicate event-entity links.'
        );

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/events?ip=198.51.100.42')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/events?host=prod-web-1&country=UG')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.infra.host', 'prod-web-1')
            ->assertJsonPath('data.0.source.country', 'UG');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/events?admin_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.actor.type', 'admin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/entry-points')
            ->assertOk()
            ->assertJsonFragment([
                'entry_key' => 'auth_login',
                'label' => 'User Login',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/attackers')
            ->assertOk()
            ->assertJsonFragment([
                'entity_key' => 'ip:198.51.100.42',
                'label' => '198.51.100.42',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/database')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slow_queries.0.time', 420)
            ->assertJsonPath('data.summary.auth_failures', 1)
            ->assertJsonPath('data.summary.privileged_writes', 1)
            ->assertJsonPath('data.summary.destructive_queries', 1)
            ->assertJsonFragment([
                'type' => 'auth_failures',
            ])
            ->assertJsonFragment([
                'type' => 'privileged_writes',
            ])
            ->assertJsonFragment([
                'type' => 'destructive_queries',
            ])
            ->assertJsonFragment([
                'title' => 'Database auth failures',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/changes')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'category' => 'routes',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/system-host')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.collector.summary.events', 4)
            ->assertJsonPath('data.collector.summary.hosts', 2)
            ->assertJsonPath('data.collector.summary.system_signals', 1)
            ->assertJsonPath('data.collector.summary.db_signals', 3)
            ->assertJsonPath('data.collector.summary.stale_sources', 1)
            ->assertJsonPath('data.collector.summary.healthy_sources', 1)
            ->assertJsonPath('data.collector.summary.reporting_streams', 2)
            ->assertJsonPath('data.collector.summary.telemetry_gaps', 3)
            ->assertJsonPath('data.collector.summary.critical_system_signals', 1)
            ->assertJsonPath('data.collector.system_breakdown.0.type', 'ssh')
            ->assertJsonFragment([
                'stream' => 'ssh',
            ])
            ->assertJsonFragment([
                'title' => 'SSH failure burst',
                'host' => 'prod-web-1',
                'status' => 'stale',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/stakeholder-risk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['admin_actors'],
                    'actors',
                ],
            ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/observability/stakeholder-risk/admin/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.actor.actor_id', (string) $admin->id)
            ->assertJsonPath('data.actor.actor_type', 'admin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/integrations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'provider' => 'zengapay',
            ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/auth-sessions/sess-test-1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.session_id', 'sess-test-1')
            ->assertJsonPath('data.session.event_count', 1);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/payments-risk/OBS-RISK-001')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_reference', 'OBS-RISK-001')
            ->assertJsonPath('data.summary.event_count', 1);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/payments-risk/OBS-COLLECT-001')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_reference', 'OBS-COLLECT-001')
            ->assertJsonPath('data.summary.event_count', 4);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/observability/incidents/suggestions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.event_count', 4);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/observability/incidents', [
            'title' => 'Webhook investigation',
            'severity' => 'high',
            'summary' => 'Investigate suspicious webhook activity',
            'notes' => 'Started from admin test',
            'event_ids' => [1],
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Webhook investigation');

        $incidentId = $createResponse->json('data.id');

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/observability/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.incident.id', $incidentId)
            ->assertJsonPath('data.summary.event_count', 1);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/observability/incidents/{$incidentId}/assign")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $admin->id)
            ->assertJsonPath('data.metadata.activity.1.action', 'assigned');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/observability/incidents/{$incidentId}", [
            'status' => 'resolved',
            'notes' => 'Contained and closed',
            'append_note' => 'Escalated to payments operations',
            'event_ids' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.notes', 'Escalated to payments operations')
            ->assertJsonPath('data.metadata.activity.2.context.detached_event_ids.0', 1)
            ->assertJsonPath('data.metadata.activity.2.context.note_appended', true)
            ->assertJsonPath('data.metadata.note_entries.0.body', 'Escalated to payments operations');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/observability/incidents/{$incidentId}/release")
            ->assertOk()
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.metadata.activity.3.action', 'released')
            ->assertJsonPath('data.metadata.activity.3.context.previous_owner.id', $admin->id);

        $this->assertGreaterThan(0, Artisan::call('observability:maintain', [
            '--rollup-hours' => 5000,
            '--prune-raw-days' => 365,
            '--prune-rollup-days' => 365,
            '--prune-integrity-days' => 365,
        ]) + 1);

        $this->assertDatabaseHas('observability_rollups_hourly', [
            'dimension_type' => 'domain',
            'dimension_key' => 'system',
        ]);

        $this->assertDatabaseMissing('observability_events', [
            'event_key' => 'test:old:1',
        ]);

        $this->assertGreaterThan(0, ObservabilityRollupHourly::query()->count());
    }
}
