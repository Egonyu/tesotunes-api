<?php

namespace App\Settings\Enums;

enum SettingType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Float = 'float';
    case Json = 'json';
    case Enum = 'enum';
    case Url = 'url';
    case Email = 'email';
    case Encrypted = 'encrypted';
    case Image = 'image';

    public function cast(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            self::Integer => (int) $raw,
            self::Float => (float) $raw,
            self::Json => is_array($raw) ? $raw : (json_decode((string) $raw, true) ?? null),
            self::Image => (string) $raw,
            default => (string) $raw,
        };
    }

    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $value ? '1' : '0',
            self::Json => is_string($value) ? $value : json_encode($value),
            self::Image => (string) $value,
            default => (string) $value,
        };
    }
}
