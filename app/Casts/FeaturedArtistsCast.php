<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Custom cast for featured artists/collaborators with validation
 *
 * Used for: Song collaborations with split percentages
 *
 * Validates:
 * - Must be array of objects
 * - Each collaborator has: user_id, role, split_percentage (optional)
 * - Split percentages must sum to ≤ 100%
 * - Valid roles: featured, producer, composer, writer, engineer
 * - User IDs must be integers
 *
 * Example structure:
 * [
 *   {"user_id": 123, "role": "featured", "split_percentage": 20},
 *   {"user_id": 456, "role": "producer", "split_percentage": 15},
 *   {"user_id": 789, "role": "composer", "split_percentage": 10}
 * ]
 */
class FeaturedArtistsCast implements CastsAttributes
{
    // Valid collaborator roles
    const VALID_ROLES = [
        'featured',      // Featured artist
        'producer',      // Music producer
        'composer',      // Song composer
        'writer',        // Lyricist/songwriter
        'engineer',      // Recording/mixing/mastering engineer
        'arranger',      // Music arranger
        'performer',     // Session musician/performer
        'remixer',       // Remixer
        'co-producer',   // Co-producer
    ];

    const MAX_COLLABORATORS = 20; // Reasonable limit
    const MAX_SPLIT_PERCENTAGE = 100;

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
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
        if ($value === null || (is_array($value) && empty($value))) {
            return json_encode([]);
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Featured artists must be an array");
        }

        $validated = $this->validate($value);

        return json_encode(array_values($validated));
    }

    /**
     * Validate collaborators structure
     */
    protected function validate(array $collaborators): array
    {
        // Check collaborator count
        if (count($collaborators) > self::MAX_COLLABORATORS) {
            throw new InvalidArgumentException("Cannot have more than " . self::MAX_COLLABORATORS . " collaborators");
        }

        $validated = [];
        $totalSplitPercentage = 0;
        $seenUserRoles = []; // Track user_id + role combinations

        foreach ($collaborators as $index => $collaborator) {
            if (!is_array($collaborator)) {
                throw new InvalidArgumentException("Collaborator at index {$index} must be an array");
            }

            // Validate required fields
            if (!isset($collaborator['user_id'])) {
                throw new InvalidArgumentException("Collaborator at index {$index} missing 'user_id'");
            }

            if (!isset($collaborator['role'])) {
                throw new InvalidArgumentException("Collaborator at index {$index} missing 'role'");
            }

            // Validate user_id
            $userId = $collaborator['user_id'];
            if (!is_int($userId) && !ctype_digit((string) $userId)) {
                throw new InvalidArgumentException("Collaborator user_id at index {$index} must be an integer");
            }
            $userId = (int) $userId;

            if ($userId <= 0) {
                throw new InvalidArgumentException("Collaborator user_id at index {$index} must be positive");
            }

            // Validate role
            $role = trim(strtolower($collaborator['role']));
            if (!in_array($role, self::VALID_ROLES)) {
                throw new InvalidArgumentException("Invalid collaborator role at index {$index}: '{$role}'. Must be one of: " . implode(', ', self::VALID_ROLES));
            }

            // Check for duplicate user+role combination
            $userRoleKey = "{$userId}_{$role}";
            if (in_array($userRoleKey, $seenUserRoles)) {
                throw new InvalidArgumentException("Duplicate collaborator: user_id {$userId} with role '{$role}' appears multiple times");
            }
            $seenUserRoles[] = $userRoleKey;

            // Build validated entry
            $validatedCollaborator = [
                'user_id' => $userId,
                'role' => $role,
            ];

            // Validate optional split_percentage
            if (isset($collaborator['split_percentage'])) {
                $splitPercentage = $collaborator['split_percentage'];

                if (!is_numeric($splitPercentage)) {
                    throw new InvalidArgumentException("Split percentage at index {$index} must be numeric");
                }

                $splitPercentage = (float) $splitPercentage;

                if ($splitPercentage < 0 || $splitPercentage > self::MAX_SPLIT_PERCENTAGE) {
                    throw new InvalidArgumentException("Split percentage at index {$index} must be between 0 and 100. Got: {$splitPercentage}");
                }

                $validatedCollaborator['split_percentage'] = round($splitPercentage, 2);
                $totalSplitPercentage += $splitPercentage;
            }

            // Optional: name for display (not validated as it may come from User relationship)
            if (isset($collaborator['name'])) {
                $validatedCollaborator['name'] = substr(trim($collaborator['name']), 0, 255);
            }

            // Optional: order for display
            if (isset($collaborator['order'])) {
                $order = (int) $collaborator['order'];
                if ($order >= 0) {
                    $validatedCollaborator['order'] = $order;
                }
            }

            $validated[] = $validatedCollaborator;
        }

        // Validate total split percentage
        if ($totalSplitPercentage > self::MAX_SPLIT_PERCENTAGE) {
            throw new InvalidArgumentException("Total split percentage cannot exceed 100%. Got: {$totalSplitPercentage}%");
        }

        return $validated;
    }

    /**
     * Get collaborators by role
     */
    public static function getByRole(array $collaborators, string $role): array
    {
        $role = strtolower(trim($role));
        return array_filter($collaborators, fn($c) => $c['role'] === $role);
    }

    /**
     * Get total split percentage allocated
     */
    public static function getTotalSplitPercentage(array $collaborators): float
    {
        $total = 0;
        foreach ($collaborators as $collaborator) {
            $total += $collaborator['split_percentage'] ?? 0;
        }
        return round($total, 2);
    }

    /**
     * Get remaining split percentage available
     */
    public static function getRemainingSplitPercentage(array $collaborators): float
    {
        return round(self::MAX_SPLIT_PERCENTAGE - self::getTotalSplitPercentage($collaborators), 2);
    }

    /**
     * Check if user is a collaborator
     */
    public static function hasUser(array $collaborators, int $userId): bool
    {
        foreach ($collaborators as $collaborator) {
            if ($collaborator['user_id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get user's roles in collaboration
     */
    public static function getUserRoles(array $collaborators, int $userId): array
    {
        $roles = [];
        foreach ($collaborators as $collaborator) {
            if ($collaborator['user_id'] === $userId) {
                $roles[] = $collaborator['role'];
            }
        }
        return $roles;
    }

    /**
     * Get user's total split percentage
     */
    public static function getUserSplitPercentage(array $collaborators, int $userId): float
    {
        $total = 0;
        foreach ($collaborators as $collaborator) {
            if ($collaborator['user_id'] === $userId) {
                $total += $collaborator['split_percentage'] ?? 0;
            }
        }
        return round($total, 2);
    }

    /**
     * Add a collaborator (with validation)
     */
    public static function addCollaborator(array $collaborators, int $userId, string $role, ?float $splitPercentage = null): array
    {
        $newCollaborator = [
            'user_id' => $userId,
            'role' => $role,
        ];

        if ($splitPercentage !== null) {
            $newCollaborator['split_percentage'] = $splitPercentage;
        }

        $collaborators[] = $newCollaborator;

        // Re-validate entire array
        $cast = new self();
        $validated = $cast->validate($collaborators);

        return $validated;
    }

    /**
     * Remove a collaborator
     */
    public static function removeCollaborator(array $collaborators, int $userId, ?string $role = null): array
    {
        return array_values(array_filter($collaborators, function($c) use ($userId, $role) {
            if ($role === null) {
                return $c['user_id'] !== $userId;
            }
            return !($c['user_id'] === $userId && $c['role'] === strtolower($role));
        }));
    }

    /**
     * Format for display
     */
    public static function formatForDisplay(array $collaborators): string
    {
        if (empty($collaborators)) {
            return '';
        }

        $names = array_map(fn($c) => $c['name'] ?? "User #{$c['user_id']}", $collaborators);
        return implode(', ', $names);
    }
}
