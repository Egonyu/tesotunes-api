<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoBoardMeeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'agenda',
        'scheduled_at',
        'venue',
        'status',
        'minutes',
        'decisions',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'decisions' => 'array',
    ];

    protected $attributes = [
        'status' => 'scheduled',
    ];

    // Relationships
    public function attendance(): HasMany
    {
        return $this->hasMany(SaccoBoardMeetingAttendance::class, 'meeting_id');
    }

    // Scopes
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now());
    }

    public function scopePast($query)
    {
        return $query->where('scheduled_at', '<', now());
    }

    // Accessors
    public function getIsUpcomingAttribute(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_at->isFuture();
    }

    public function getIsPastAttribute(): bool
    {
        return $this->scheduled_at->isPast();
    }

    public function getAttendanceRateAttribute(): float
    {
        $total = $this->attendance()->count();
        if ($total === 0) {
            return 0;
        }

        $present = $this->attendance()->where('status', 'present')->count();
        return ($present / $total) * 100;
    }
}
