<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Like extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'likeable_type',
        'likeable_id',
        'type',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('likeable_type', $type);
    }

    // Static methods
    public static function toggle(User $user, Model $likeable): bool
    {
        $like = static::where('user_id', $user->id)
            ->where('likeable_type', get_class($likeable))
            ->where('likeable_id', $likeable->id)
            ->where('type', 'like')
            ->first();

        if ($like) {
            $like->delete();
            $likeable->decrement('like_count');

            // Create activity for unlike
            Activity::createForUser($user, 'unliked_' . class_basename($likeable), $likeable);

            return false; // Unliked
        } else {
            static::create([
                'user_id' => $user->id,
                'likeable_type' => get_class($likeable),
                'likeable_id' => $likeable->id,
            ]);

            $likeable->increment('like_count');

            // Create activity for like
            Activity::createForUser($user, 'liked_' . class_basename($likeable), $likeable);

            // Notify content owner (if not self-like)
            if (method_exists($likeable, 'user') && $likeable->user && $likeable->user->id !== $user->id) {
                $likeable->user->notifications()->create([
                    'notification_type' => 'content_liked',  // Fixed: was 'type'
                    'title' => 'New Like',
                    'message' => "{$user->name} liked your " . class_basename($likeable),
                    'metadata' => [  // Fixed: was 'data'
                        'liker_id' => $user->id,
                        'likeable_type' => get_class($likeable),
                        'likeable_id' => $likeable->id,
                    ],
                ]);
            }

            return true; // Liked
        }
    }
}