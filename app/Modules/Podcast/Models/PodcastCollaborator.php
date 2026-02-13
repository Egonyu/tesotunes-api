<?php

namespace App\Modules\Podcast\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastCollaborator extends Model
{
    use HasFactory;

    protected $fillable = [
        'podcast_id',
        'user_id',
        'role',
        'can_edit',
        'can_publish',
        'can_delete',
        'can_manage_episodes',
        'revenue_split_percentage',
        'status',
        'invited_at',
        'accepted_at',
    ];

    protected $casts = [
        'can_edit' => 'boolean',
        'can_publish' => 'boolean',
        'can_delete' => 'boolean',
        'can_manage_episodes' => 'boolean',
        'revenue_split_percentage' => 'decimal:2',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\Podcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInvited($query)
    {
        return $query->where('status', 'invited');
    }

    public function accept(): void
    {
        $this->update([
            'status' => 'active',
            'accepted_at' => now(),
        ]);
    }

    public function remove(): void
    {
        $this->update(['status' => 'removed']);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
