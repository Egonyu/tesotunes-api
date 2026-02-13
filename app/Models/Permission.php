<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'group',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
                    ->withTimestamps();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('group', $category);
    }

    // Permission categories
    public const CATEGORIES = [
        'music' => 'Music Management',
        'user' => 'User Management',
        'admin' => 'Administration',
        'payment' => 'Payment Management',
        'social' => 'Social Features',
        'content' => 'Content Moderation',
    ];

    // Predefined permissions
    public static function getDefaultPermissions(): array
    {
        return [
            // Music permissions
            ['name' => 'Play Music', 'slug' => 'music.play', 'group' => 'music'],
            ['name' => 'Upload Music', 'slug' => 'music.upload', 'group' => 'music'],
            ['name' => 'Edit Own Music', 'slug' => 'music.edit_own', 'group' => 'music'],
            ['name' => 'Edit Any Music', 'slug' => 'music.edit_any', 'group' => 'music'],
            ['name' => 'Delete Own Music', 'slug' => 'music.delete_own', 'group' => 'music'],
            ['name' => 'Delete Any Music', 'slug' => 'music.delete_any', 'group' => 'music'],
            ['name' => 'Moderate Music', 'slug' => 'music.moderate', 'group' => 'music'],
            ['name' => 'Like Music', 'slug' => 'music.like', 'group' => 'music'],
            ['name' => 'Share Music', 'slug' => 'music.share', 'group' => 'music'],

            // Album permissions
            ['name' => 'Create Albums', 'slug' => 'album.create', 'group' => 'music'],
            ['name' => 'Edit Own Albums', 'slug' => 'album.edit_own', 'group' => 'music'],
            ['name' => 'Edit Any Albums', 'slug' => 'album.edit_any', 'group' => 'music'],
            ['name' => 'Delete Own Albums', 'slug' => 'album.delete_own', 'group' => 'music'],
            ['name' => 'Delete Any Albums', 'slug' => 'album.delete_any', 'group' => 'music'],

            // Playlist permissions
            ['name' => 'Create Playlists', 'slug' => 'playlist.create', 'group' => 'social'],
            ['name' => 'Edit Own Playlists', 'slug' => 'playlist.edit_own', 'group' => 'social'],
            ['name' => 'Edit Any Playlists', 'slug' => 'playlist.edit_any', 'group' => 'social'],
            ['name' => 'Delete Own Playlists', 'slug' => 'playlist.delete_own', 'group' => 'social'],
            ['name' => 'Delete Any Playlists', 'slug' => 'playlist.delete_any', 'group' => 'social'],

            // User permissions
            ['name' => 'View Users', 'slug' => 'user.view', 'group' => 'user'],
            ['name' => 'Create Users', 'slug' => 'user.create', 'group' => 'user'],
            ['name' => 'Edit Users', 'slug' => 'user.edit', 'group' => 'user'],
            ['name' => 'Delete Users', 'slug' => 'user.delete', 'group' => 'user'],
            ['name' => 'Moderate Users', 'slug' => 'user.moderate', 'group' => 'user'],
            ['name' => 'Ban Users', 'slug' => 'user.ban', 'group' => 'user'],

            // Profile permissions
            ['name' => 'Edit Own Profile', 'slug' => 'profile.edit_own', 'group' => 'user'],
            ['name' => 'Edit Any Profile', 'slug' => 'profile.edit_any', 'group' => 'user'],

            // Social permissions
            ['name' => 'Follow Users', 'slug' => 'follow.users', 'group' => 'social'],
            ['name' => 'Create Comments', 'slug' => 'comment.create', 'group' => 'social'],
            ['name' => 'Edit Own Comments', 'slug' => 'comment.edit_own', 'group' => 'social'],
            ['name' => 'Edit Any Comments', 'slug' => 'comment.edit_any', 'group' => 'social'],
            ['name' => 'Delete Own Comments', 'slug' => 'comment.delete_own', 'group' => 'social'],
            ['name' => 'Delete Any Comments', 'slug' => 'comment.delete_any', 'group' => 'social'],
            ['name' => 'Moderate Comments', 'slug' => 'comment.moderate', 'group' => 'content'],

            // Admin permissions
            ['name' => 'Access Admin Dashboard', 'slug' => 'admin.dashboard', 'group' => 'admin'],
            ['name' => 'Manage Users', 'slug' => 'admin.users', 'group' => 'admin'],
            ['name' => 'Manage Music', 'slug' => 'admin.music', 'group' => 'admin'],
            ['name' => 'Manage Payments', 'slug' => 'admin.payments', 'group' => 'admin'],
            ['name' => 'View Reports', 'slug' => 'admin.reports', 'group' => 'admin'],
            ['name' => 'Manage Settings', 'slug' => 'admin.settings', 'group' => 'admin'],

            // Analytics permissions
            ['name' => 'View Own Analytics', 'slug' => 'analytics.view_own', 'group' => 'music'],
            ['name' => 'View Any Analytics', 'slug' => 'analytics.view_any', 'group' => 'admin'],

            // Payment permissions
            ['name' => 'View Own Payments', 'slug' => 'payment.view_own', 'group' => 'payment'],
            ['name' => 'Manage Payments', 'slug' => 'payment.manage', 'group' => 'payment'],

            // Report permissions
            ['name' => 'Create Reports', 'slug' => 'report.create', 'group' => 'content'],
            ['name' => 'Handle Reports', 'slug' => 'report.handle', 'group' => 'content'],
        ];
    }
}