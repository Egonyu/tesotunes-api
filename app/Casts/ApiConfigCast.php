<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Custom cast for API configuration with encryption
 *
 * Used for: Distribution platform credentials
 *
 * Validates:
 * - Must be array
 * - Required keys: api_key, api_secret
 * - Optional keys: endpoint, version, region, additional_headers
 * - Encrypts sensitive data (api_key, api_secret)
 * - Maximum field lengths enforced
 *
 * Example structure:
 * {
 *   "api_key": "encrypted_key",
 *   "api_secret": "encrypted_secret",
 *   "endpoint": "https://api.example.com",
 *   "version": "v1",
 *   "region": "global",
 *   "additional_headers": {"X-Custom": "value"}
 * }
 */
class ApiConfigCast implements CastsAttributes
{
    // Required fields
    const REQUIRED_FIELDS = ['api_key', 'api_secret'];

    // Optional fields with validation
    const OPTIONAL_FIELDS = [
        'endpoint',
        'version',
        'region',
        'additional_headers',
        'timeout',
        'retry_attempts',
        'webhook_url',
        'callback_url',
    ];

    // Sensitive fields that should be encrypted
    const ENCRYPTED_FIELDS = ['api_key', 'api_secret', 'webhook_secret', 'oauth_token'];

    /**
     * Cast the given value (decrypt sensitive fields).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        // Decrypt sensitive fields
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($decoded[$field]) && !empty($decoded[$field])) {
                try {
                    $decoded[$field] = Crypt::decryptString($decoded[$field]);
                } catch (\Exception $e) {
                    // If decryption fails, the value might not be encrypted (legacy data)
                    // Keep the original value
                    \Log::warning("Failed to decrypt API config field: {$field}", [
                        'model' => get_class($model),
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $decoded;
    }

    /**
     * Prepare the given value for storage (encrypt sensitive fields).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("API config must be an array");
        }

        // Validate structure
        $validated = $this->validate($value);

        // Encrypt sensitive fields
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($validated[$field]) && !empty($validated[$field])) {
                $validated[$field] = Crypt::encryptString($validated[$field]);
            }
        }

        return json_encode($validated);
    }

    /**
     * Validate API config structure
     */
    protected function validate(array $config): array
    {
        // Check required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new InvalidArgumentException("API config missing required field: {$field}");
            }

            if (!is_string($config[$field])) {
                throw new InvalidArgumentException("API config field '{$field}' must be a string");
            }

            // Length validation
            if (strlen($config[$field]) > 500) {
                throw new InvalidArgumentException("API config field '{$field}' exceeds maximum length of 500 characters");
            }
        }

        // Validate optional fields
        $validated = [];
        $allFields = array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS, self::ENCRYPTED_FIELDS);

        foreach ($config as $field => $fieldValue) {
            if (!in_array($field, $allFields)) {
                throw new InvalidArgumentException("Unknown API config field: {$field}");
            }

            // Type-specific validation
            switch ($field) {
                case 'endpoint':
                case 'webhook_url':
                case 'callback_url':
                    if (!filter_var($fieldValue, FILTER_VALIDATE_URL)) {
                        throw new InvalidArgumentException("API config field '{$field}' must be a valid URL");
                    }
                    break;

                case 'timeout':
                case 'retry_attempts':
                    if (!is_int($fieldValue) || $fieldValue < 0) {
                        throw new InvalidArgumentException("API config field '{$field}' must be a positive integer");
                    }
                    break;

                case 'additional_headers':
                    if (!is_array($fieldValue)) {
                        throw new InvalidArgumentException("API config field 'additional_headers' must be an array");
                    }
                    // Validate header format
                    foreach ($fieldValue as $headerKey => $headerValue) {
                        if (!is_string($headerKey) || !is_string($headerValue)) {
                            throw new InvalidArgumentException("Additional headers must be string key-value pairs");
                        }
                    }
                    break;

                case 'version':
                    if (!is_string($fieldValue) || !preg_match('/^v?\d+(\.\d+)*$/', $fieldValue)) {
                        throw new InvalidArgumentException("API version must be in format: v1, v1.0, 1.0, etc.");
                    }
                    break;

                case 'region':
                    if (!is_string($fieldValue)) {
                        throw new InvalidArgumentException("Region must be a string");
                    }
                    break;
            }

            $validated[$field] = $fieldValue;
        }

        return $validated;
    }

    /**
     * Mask sensitive fields for display (replace with asterisks)
     */
    public static function maskSensitiveFields(array $config): array
    {
        $masked = $config;

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($masked[$field]) && !empty($masked[$field])) {
                $value = $masked[$field];
                $length = strlen($value);

                if ($length <= 4) {
                    $masked[$field] = str_repeat('*', $length);
                } else {
                    // Show first 2 and last 2 characters
                    $masked[$field] = substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
                }
            }
        }

        return $masked;
    }

    /**
     * Test API connectivity with config
     */
    public static function testConnection(array $config): bool
    {
        // This would contain actual API testing logic
        // For now, just validate structure
        try {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!isset($config[$field])) {
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
