<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

class EnvironmentSettingsService
{
    /**
     * Curated env keys that are operationally useful and safe enough to manage
     * from the admin UI. This intentionally excludes raw database/app secrets.
     */
    private const GROUPS = [
        [
            'id' => 'application',
            'label' => 'Application',
            'description' => 'Core API environment, base URLs, and runtime toggles.',
            'fields' => [
                ['key' => 'APP_NAME', 'label' => 'App Name', 'type' => 'string', 'secret' => false, 'description' => 'Laravel application name shown in operational surfaces.'],
                ['key' => 'APP_ENV', 'label' => 'App Environment', 'type' => 'string', 'secret' => false, 'options' => ['local', 'staging', 'production'], 'description' => 'Runtime environment label used for diagnostics and integrations.'],
                ['key' => 'APP_DEBUG', 'label' => 'Debug Mode', 'type' => 'boolean', 'secret' => false, 'description' => 'Whether Laravel should expose debug output.'],
                ['key' => 'APP_URL', 'label' => 'API Base URL', 'type' => 'string', 'secret' => false, 'description' => 'Canonical backend URL used in generated callbacks.'],
                ['key' => 'FRONTEND_URL', 'label' => 'Frontend URL', 'type' => 'string', 'secret' => false, 'description' => 'Public frontend base URL for redirects and CORS.'],
            ],
        ],
        [
            'id' => 'mail',
            'label' => 'Mail',
            'description' => 'Outgoing email transport configuration for the API.',
            'fields' => [
                ['key' => 'MAIL_MAILER', 'label' => 'Mailer', 'type' => 'string', 'secret' => false, 'options' => ['log', 'smtp', 'ses', 'resend', 'postmark', 'array'], 'description' => 'Laravel mail transport.'],
                ['key' => 'MAIL_HOST', 'label' => 'SMTP Host', 'type' => 'string', 'secret' => false, 'description' => 'Mail server hostname.'],
                ['key' => 'MAIL_PORT', 'label' => 'SMTP Port', 'type' => 'integer', 'secret' => false, 'description' => 'Mail server port.'],
                ['key' => 'MAIL_USERNAME', 'label' => 'SMTP Username', 'type' => 'string', 'secret' => false, 'description' => 'Username for SMTP authentication.'],
                ['key' => 'MAIL_PASSWORD', 'label' => 'SMTP Password', 'type' => 'string', 'secret' => true, 'description' => 'Password for SMTP authentication.'],
                ['key' => 'MAIL_FROM_ADDRESS', 'label' => 'From Address', 'type' => 'string', 'secret' => false, 'description' => 'Default sender email.'],
                ['key' => 'MAIL_FROM_NAME', 'label' => 'From Name', 'type' => 'string', 'secret' => false, 'description' => 'Default sender display name.'],
            ],
        ],
        [
            'id' => 'queues',
            'label' => 'Queues & Realtime',
            'description' => 'Background job and realtime transport settings.',
            'fields' => [
                ['key' => 'QUEUE_CONNECTION', 'label' => 'Queue Connection', 'type' => 'string', 'secret' => false, 'options' => ['sync', 'database', 'redis', 'sqs'], 'description' => 'Laravel queue driver.'],
                ['key' => 'BROADCAST_CONNECTION', 'label' => 'Broadcast Connection', 'type' => 'string', 'secret' => false, 'options' => ['log', 'reverb', 'pusher', 'ably', 'null'], 'description' => 'Realtime broadcasting driver.'],
                ['key' => 'PUSHER_APP_ID', 'label' => 'Pusher App ID', 'type' => 'string', 'secret' => false, 'description' => 'Pusher application identifier.'],
                ['key' => 'PUSHER_APP_KEY', 'label' => 'Pusher App Key', 'type' => 'string', 'secret' => false, 'description' => 'Pusher public key.'],
                ['key' => 'PUSHER_APP_SECRET', 'label' => 'Pusher App Secret', 'type' => 'string', 'secret' => true, 'description' => 'Pusher secret used by the API.'],
                ['key' => 'PUSHER_HOST', 'label' => 'Pusher Host', 'type' => 'string', 'secret' => false, 'description' => 'Optional self-hosted websocket host.'],
                ['key' => 'PUSHER_PORT', 'label' => 'Pusher Port', 'type' => 'integer', 'secret' => false, 'description' => 'Optional websocket port.'],
                ['key' => 'PUSHER_SCHEME', 'label' => 'Pusher Scheme', 'type' => 'string', 'secret' => false, 'options' => ['http', 'https'], 'description' => 'Transport scheme for websocket connections.'],
                ['key' => 'REVERB_APP_ID', 'label' => 'Reverb App ID', 'type' => 'string', 'secret' => false, 'description' => 'Laravel Reverb app identifier.'],
                ['key' => 'REVERB_APP_KEY', 'label' => 'Reverb App Key', 'type' => 'string', 'secret' => false, 'description' => 'Laravel Reverb public key.'],
                ['key' => 'REVERB_APP_SECRET', 'label' => 'Reverb App Secret', 'type' => 'string', 'secret' => true, 'description' => 'Laravel Reverb secret used by the API.'],
                ['key' => 'REVERB_HOST', 'label' => 'Reverb Host', 'type' => 'string', 'secret' => false, 'description' => 'Public Reverb host.'],
                ['key' => 'REVERB_PORT', 'label' => 'Reverb Port', 'type' => 'integer', 'secret' => false, 'description' => 'Public Reverb port.'],
                ['key' => 'REVERB_SCHEME', 'label' => 'Reverb Scheme', 'type' => 'string', 'secret' => false, 'options' => ['http', 'https'], 'description' => 'Transport scheme for Reverb.'],
            ],
        ],
        [
            'id' => 'storage',
            'label' => 'Storage',
            'description' => 'Object storage configuration used by the API.',
            'fields' => [
                ['key' => 'FILESYSTEM_DISK', 'label' => 'Filesystem Disk', 'type' => 'string', 'secret' => false, 'options' => ['local', 's3', 'digitalocean', 'public'], 'description' => 'Default Laravel filesystem disk.'],
                ['key' => 'MEDIA_DISK', 'label' => 'Media Disk', 'type' => 'string', 'secret' => false, 'description' => 'Disk used for media-library assets.'],
                ['key' => 'AWS_ACCESS_KEY_ID', 'label' => 'AWS Access Key ID', 'type' => 'string', 'secret' => false, 'description' => 'Access key for S3-compatible storage.'],
                ['key' => 'AWS_SECRET_ACCESS_KEY', 'label' => 'AWS Secret Access Key', 'type' => 'string', 'secret' => true, 'description' => 'Secret key for S3-compatible storage.'],
                ['key' => 'AWS_DEFAULT_REGION', 'label' => 'AWS Region', 'type' => 'string', 'secret' => false, 'description' => 'Default bucket region.'],
                ['key' => 'AWS_BUCKET', 'label' => 'AWS Bucket', 'type' => 'string', 'secret' => false, 'description' => 'Primary S3 bucket name.'],
                ['key' => 'DO_SPACES_ACCESS_KEY_ID', 'label' => 'Spaces Access Key ID', 'type' => 'string', 'secret' => false, 'description' => 'DigitalOcean Spaces access key.'],
                ['key' => 'DO_SPACES_SECRET_ACCESS_KEY', 'label' => 'Spaces Secret Access Key', 'type' => 'string', 'secret' => true, 'description' => 'DigitalOcean Spaces secret key.'],
                ['key' => 'DO_SPACES_ENDPOINT', 'label' => 'Spaces Endpoint', 'type' => 'string', 'secret' => false, 'description' => 'DigitalOcean Spaces endpoint URL.'],
                ['key' => 'DO_SPACES_REGION', 'label' => 'Spaces Region', 'type' => 'string', 'secret' => false, 'description' => 'DigitalOcean Spaces region.'],
                ['key' => 'DO_SPACES_BUCKET', 'label' => 'Spaces Bucket', 'type' => 'string', 'secret' => false, 'description' => 'DigitalOcean Spaces bucket.'],
                ['key' => 'DO_SPACES_CDN_ENDPOINT', 'label' => 'Spaces CDN Endpoint', 'type' => 'string', 'secret' => false, 'description' => 'Optional CDN endpoint for media delivery.'],
            ],
        ],
        [
            'id' => 'monitoring',
            'label' => 'Monitoring',
            'description' => 'Observability configuration that affects incident visibility.',
            'fields' => [
                ['key' => 'SENTRY_LARAVEL_DSN', 'label' => 'Sentry DSN', 'type' => 'string', 'secret' => true, 'description' => 'Sentry DSN used by the API.'],
                ['key' => 'SENTRY_ENVIRONMENT', 'label' => 'Sentry Environment', 'type' => 'string', 'secret' => false, 'description' => 'Sentry environment tag.'],
                ['key' => 'SENTRY_RELEASE', 'label' => 'Sentry Release', 'type' => 'string', 'secret' => false, 'description' => 'Optional Sentry release identifier.'],
                ['key' => 'SENTRY_TRACES_SAMPLE_RATE', 'label' => 'Trace Sample Rate', 'type' => 'number', 'secret' => false, 'description' => 'Trace sample rate between 0 and 1.'],
            ],
        ],
    ];

    public function __construct(
        private readonly ?string $envPath = null
    ) {
    }

    public function definitions(): array
    {
        return self::GROUPS;
    }

    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->definitions() as $group) {
            foreach ($group['fields'] as $field) {
                $rules["values.{$field['key']}"] = $this->ruleForField($field);
            }
        }

        return $rules;
    }

    public function getEditableSettings(): array
    {
        return array_map(function (array $group) {
            return [
                'id' => $group['id'],
                'label' => $group['label'],
                'description' => $group['description'],
                'fields' => array_map(function (array $field) {
                    $currentValue = $this->readEnvValue($field['key']);

                    return [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'description' => $field['description'],
                        'type' => $field['type'],
                        'secret' => $field['secret'],
                        'options' => $field['options'] ?? [],
                        'configured' => $currentValue !== null && $currentValue !== '',
                        'value' => $field['secret'] ? null : $this->castForOutput($field['type'], $currentValue),
                    ];
                }, $group['fields']),
            ];
        }, $this->definitions());
    }

    public function update(array $values): array
    {
        $definitions = collect($this->definitions())
            ->flatMap(fn (array $group) => $group['fields'])
            ->keyBy('key');

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! $definitions->has($key)) {
                continue;
            }

            $field = $definitions->get($key);
            $normalized[$key] = $this->normalizeForStorage($field['type'], $value);
        }

        if ($normalized === []) {
            return [];
        }

        $this->writeEnvValues($normalized);
        $this->refreshRuntimeCaches();

        return array_keys($normalized);
    }

    private function ruleForField(array $field): array
    {
        $rules = ['nullable'];

        switch ($field['type']) {
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'integer':
                $rules[] = 'integer';
                break;
            case 'number':
                $rules[] = 'numeric';
                break;
            default:
                $rules[] = 'string';
                break;
        }

        if (! empty($field['options'])) {
            $rules[] = 'in:'.implode(',', $field['options']);
        }

        return $rules;
    }

    private function castForOutput(string $type, string|bool|int|float|null $value): string|bool|int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'number' => (float) $value,
            default => (string) $value,
        };
    }

    private function normalizeForStorage(string $type, mixed $value): string
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'integer' => (string) ((int) $value),
            'number' => (string) ((float) $value),
            default => trim((string) $value),
        };
    }

    private function readEnvValue(string $key): string|null
    {
        $path = $this->envPath();

        if (! File::exists($path)) {
            return env($key);
        }

        $contents = File::get($path);

        if (! preg_match("/^{$this->quotedKey($key)}=(.*)$/m", $contents, $matches)) {
            $fallback = env($key);

            return is_scalar($fallback) ? (string) $fallback : null;
        }

        return $this->decodeEnvValue($matches[1] ?? '');
    }

    private function writeEnvValues(array $values): void
    {
        $path = $this->envPath();

        if (! File::exists($path)) {
            throw new RuntimeException('Environment file was not found.');
        }

        $contents = File::get($path);
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            $encodedValue = $this->encodeEnvValue($value);
            $pattern = "/^{$this->quotedKey($key)}=.*$/m";

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, "{$key}={$encodedValue}", $contents, 1) ?? $contents;
                continue;
            }

            $contents = rtrim($contents).$eol."{$key}={$encodedValue}{$eol}";
        }

        File::put($path, $contents);
    }

    private function refreshRuntimeCaches(): void
    {
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
        } catch (\Throwable) {
            // Ignore cache refresh failures so the write still succeeds.
        }
    }

    private function envPath(): string
    {
        return $this->envPath ?: app()->environmentFilePath();
    }

    private function quotedKey(string $key): string
    {
        return preg_quote($key, '/');
    }

    private function decodeEnvValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === 'null') {
            return '';
        }

        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            $inner = substr($trimmed, 1, -1);

            return stripcslashes($inner);
        }

        return $trimmed;
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s|#|"|=/', $value) === 1) {
            return '"'.addcslashes($value, "\\\"\n\r\t").'"';
        }

        return $value;
    }
}
