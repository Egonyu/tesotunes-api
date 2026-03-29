<?php

namespace App\Services\Observability;

use App\Services\Payment\PaymentObservabilityService;
use Illuminate\Support\Collection;

class ObservabilityCatalogService
{
    public function __construct(
        protected PaymentObservabilityService $payments,
    ) {}

    public function entryPoints(): Collection
    {
        $core = collect([
            [
                'entry_key' => 'auth_login',
                'label' => 'User Login',
                'subsystem' => 'auth',
                'route_pattern' => '/api/login',
                'methods' => ['POST'],
                'exposure_type' => 'public',
                'criticality' => 'high',
                'metadata' => ['surface' => 'auth'],
            ],
            [
                'entry_key' => 'auth_register',
                'label' => 'Registration',
                'subsystem' => 'auth',
                'route_pattern' => '/api/register',
                'methods' => ['POST'],
                'exposure_type' => 'public',
                'criticality' => 'medium',
                'metadata' => ['surface' => 'auth'],
            ],
            [
                'entry_key' => 'admin_api',
                'label' => 'Admin API',
                'subsystem' => 'admin',
                'route_pattern' => '/api/admin/*',
                'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'exposure_type' => 'admin',
                'criticality' => 'critical',
                'metadata' => ['surface' => 'admin'],
            ],
            [
                'entry_key' => 'uploads',
                'label' => 'Uploads',
                'subsystem' => 'upload',
                'route_pattern' => '/api/artist/songs|/api/artist/profile/*',
                'methods' => ['POST'],
                'exposure_type' => 'authenticated',
                'criticality' => 'high',
                'metadata' => ['surface' => 'media'],
            ],
            [
                'entry_key' => 'public_catalog',
                'label' => 'Public Content',
                'subsystem' => 'content',
                'route_pattern' => '/api/featured|/api/events/*|/api/podcasts/*',
                'methods' => ['GET', 'POST'],
                'exposure_type' => 'public',
                'criticality' => 'medium',
                'metadata' => ['surface' => 'content'],
            ],
            [
                'entry_key' => 'webhook_inbound',
                'label' => 'Inbound Webhooks',
                'subsystem' => 'webhook',
                'route_pattern' => '/api/webhooks/*',
                'methods' => ['POST'],
                'exposure_type' => 'integration',
                'criticality' => 'critical',
                'metadata' => ['surface' => 'integrations'],
            ],
        ]);

        $paymentEntries = collect($this->payments->entryPoints())->map(function (array $entry) {
            return [
                'entry_key' => 'payment_'.$entry['key'],
                'label' => $entry['label'],
                'subsystem' => 'payments',
                'route_pattern' => collect([
                    ...($entry['initiation_endpoints'] ?? []),
                    ...($entry['status_endpoints'] ?? []),
                    ...($entry['webhook_endpoints'] ?? []),
                ])->filter()->implode(' | '),
                'methods' => $this->extractMethods($entry),
                'exposure_type' => 'payment',
                'criticality' => ($entry['metrics']['open_issues'] ?? 0) > 0 ? 'critical' : 'high',
                'metadata' => $entry,
            ];
        });

        return $core->concat($paymentEntries)->values();
    }

    protected function extractMethods(array $entry): array
    {
        return collect([
            ...($entry['initiation_endpoints'] ?? []),
            ...($entry['status_endpoints'] ?? []),
            ...($entry['webhook_endpoints'] ?? []),
        ])->map(function (string $endpoint) {
            return strtoupper(strtok($endpoint, ' '));
        })->filter()->unique()->values()->all();
    }
}
