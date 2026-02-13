<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'permissions',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'permissions' => \App\Casts\PermissionsCast::class,
        'is_active' => 'boolean',
    ];

    // Relationships
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
                    ->withPivot(['assigned_at', 'assigned_by', 'is_active'])
                    ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
                    ->withTimestamps();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    // Helper methods
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []) ||
               $this->permissions()->where('name', $permission)->exists();
    }

    public function addPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->update(['permissions' => $permissions]);
        }
    }

    public function removePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_filter($permissions, fn($p) => $p !== $permission);
        $this->update(['permissions' => array_values($permissions)]);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    // Predefined role constants
    public const USER = 'user';
    public const ARTIST = 'artist';
    public const MODERATOR = 'moderator';
    public const ADMIN = 'admin';
    public const SUPER_ADMIN = 'super_admin';

    public static function getDefaultPermissions(string $roleName): array
    {
        return match($roleName) {
            self::USER => [
                'music.play',
                'music.like',
                'music.share',
                'playlist.create',
                'playlist.edit_own',
                'profile.edit_own',
                'comment.create',
                'follow.users',
            ],
            self::ARTIST => [
                'music.play',
                'music.like',
                'music.share',
                'music.upload',
                'music.edit_own',
                'music.delete_own',
                'album.create',
                'album.edit_own',
                'playlist.create',
                'playlist.edit_own',
                'profile.edit_own',
                'analytics.view_own',
                'comment.create',
                'follow.users',
            ],
            self::MODERATOR => [
                'music.play',
                'music.like',
                'music.share',
                'music.moderate',
                'comment.moderate',
                'user.moderate',
                'report.handle',
                'playlist.create',
                'playlist.edit_own',
                'profile.edit_own',
                'comment.create',
                'follow.users',
            ],
            self::ADMIN => [
                'music.*',
                'user.*',
                'playlist.*',
                'admin.dashboard',
                'admin.users',
                'admin.music',
                'admin.payments',
                'admin.reports',
                'admin.settings',
                'comment.*',
                'follow.*',
            ],
            self::SUPER_ADMIN => ['*'],
            default => [],
        };
    }
}