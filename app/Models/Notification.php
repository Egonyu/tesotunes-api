<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'category',
        'title',
        'message',
        'action_url',
        'action_text',
        'notifiable_type',
        'notifiable_id',
        'actor_id',
        'icon',
        'image',
        'is_read',
        'read_at',
        'priority',
        'channels',
        'emailed_at',
        'pushed_at',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'channels' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'emailed_at' => 'datetime',
        'pushed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($notification) {
            if (empty($notification->uuid)) {
                $notification->uuid = Str::uuid();
            }
        });
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function emailQueue(): HasOne
    {
        return $this->hasOne(EmailQueue::class);
    }

    // Scopes
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // Accessors
    public function getCreatedAtFormattedAttribute(): string
    {
        return $this->created_at->format('M j, Y \a\t g:i A');
    }

    public function getCreatedAtRelativeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    public function getIconAttribute(): string
    {
        // If icon is explicitly set, use it
        if (isset($this->attributes['icon']) && $this->attributes['icon']) {
            return $this->attributes['icon'];
        }

        // Otherwise, derive from type
        return match($this->type ?? 'default') {
            'new_follower' => 'user-plus',
            'playlist_activity' => 'music',
            'artist_release' => 'disc',
            'comment_reply' => 'message-circle',
            'content_liked' => 'heart',
            'mentioned_in_share' => 'at-sign',
            'collaboration_invite' => 'users',
            'collaboration_accepted' => 'check-circle',
            'collaboration_declined' => 'x-circle',
            default => 'bell',
        };
    }

    public function getColorClassAttribute(): string
    {
        return match($this->type ?? 'default') {
            'new_follower' => 'text-blue-600',
            'playlist_activity' => 'text-green-600',
            'artist_release' => 'text-purple-600',
            'comment_reply' => 'text-yellow-600',
            'content_liked' => 'text-red-600',
            'mentioned_in_share' => 'text-indigo-600',
            'collaboration_invite' => 'text-cyan-600',
            'collaboration_accepted' => 'text-green-600',
            'collaboration_declined' => 'text-red-600',
            default => 'text-gray-600',
        };
    }

    public function isEmailed(): bool
    {
        return $this->emailed_at !== null;
    }

    public function isPushed(): bool
    {
        return $this->pushed_at !== null;
    }

    public function wasDeliveredVia(string $channel): bool
    {
        return in_array($channel, $this->channels ?? []);
    }

    // Helper methods
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    public function markAsUnread(): void
    {
        $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    // Static methods
    public function markAllAsRead(User $user): void
    {
        static::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public static function getUnreadCount(User $user): int
    {
        return static::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();
    }

    public static function createForUser(User $user, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null, string $category = 'general'): self
    {
        return static::create([
            'user_id' => $user->id,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_url' => $actionUrl,
        ]);
    }

    // Batch notifications for African market efficiency
    public static function createBatchForUsers(array $userIds, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null, string $category = 'general'): void
    {
        $notifications = [];
        $now = now();

        foreach ($userIds as $userId) {
            $notifications[] = [
                'uuid' => Str::uuid(),
                'user_id' => $userId,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'message' => $message,
                'data' => json_encode($data),
                'action_url' => $actionUrl,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Batch insert for efficiency
        static::insert($notifications);
    }

    public static function cleanupOld(int $days = 90): int
    {
        // Clean up old read notifications to keep database lean
        return static::where('is_read', true)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}