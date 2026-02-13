<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Custom cast for role permissions JSON field
 *
 * Validates:
 * - Must be array
 * - Each permission must be string
 * - No duplicate permissions
 * - Permissions follow format: 'resource.action' or '*'
 * - Valid actions: view, create, edit, delete, moderate, *
 *
 * Examples:
 * - 'music.play', 'music.upload', 'admin.*', '*'
 */
class PermissionsCast implements CastsAttributes
{
    // Valid permission actions
    const VALID_ACTIONS = ['view', 'create', 'edit', 'delete', 'moderate', 'manage', 'list', 'show', 'store', 'update', 'destroy', 'approve', 'reject', 'play', 'upload', 'download', 'share', 'like', 'follow', 'comment', 'handle', 'dashboard', 'access', '*'];

    // Valid resources
    const VALID_RESOURCES = [
        'music', 'album', 'playlist', 'user', 'artist', 'admin', 'comment',
        'follow', 'like', 'share', 'analytics', 'report', 'payout',
        'profile', 'settings', 'payment', 'subscription', 'credit',
        'distribution', 'award', 'event', 'podcast', 'store', 'sacco', '*'
    ];

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            return json_encode([]);
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Permissions must be an array");
        }

        // Validate each permission
        $validated = [];
        foreach ($value as $permission) {
            if (!is_string($permission)) {
                throw new InvalidArgumentException("Each permission must be a string");
            }

            $validatedPermission = $this->validatePermission($permission);
            if (!in_array($validatedPermission, $validated)) {
                $validated[] = $validatedPermission;
            }
        }

        return json_encode(array_values($validated));
    }

    /**
     * Validate a single permission string
     */
    protected function validatePermission(string $permission): string
    {
        $permission = trim($permission);

        // Super admin wildcard
        if ($permission === '*') {
            return '*';
        }

        // Must contain a dot (resource.action)
        if (!str_contains($permission, '.')) {
            throw new InvalidArgumentException("Permission must be in format 'resource.action' or '*'. Got: {$permission}");
        }

        [$resource, $action] = explode('.', $permission, 2);

        $resource = trim($resource);
        $action = trim($action);

        // Validate resource
        if (!in_array($resource, self::VALID_RESOURCES)) {
            throw new InvalidArgumentException("Invalid permission resource: {$resource}. Must be one of: " . implode(', ', self::VALID_RESOURCES));
        }

        // Validate action
        if ($action !== '*' && !in_array($action, self::VALID_ACTIONS)) {
            throw new InvalidArgumentException("Invalid permission action: {$action}. Must be one of: " . implode(', ', self::VALID_ACTIONS));
        }

        return "{$resource}.{$action}";
    }

    /**
     * Check if permissions array contains a specific permission
     */
    public static function hasPermission(array $permissions, string $permission): bool
    {
        // Check for super admin wildcard
        if (in_array('*', $permissions)) {
            return true;
        }

        // Check exact match
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Check wildcard resource (e.g., 'admin.*' grants 'admin.users')
        [$resource, $action] = explode('.', $permission, 2);
        if (in_array("{$resource}.*", $permissions)) {
            return true;
        }

        return false;
    }

    /**
     * Merge multiple permission arrays
     */
    public static function merge(array ...$permissionArrays): array
    {
        $merged = [];
        foreach ($permissionArrays as $permissions) {
            $merged = array_merge($merged, $permissions);
        }
        return array_values(array_unique($merged));
    }
}
