<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class SaccoMeeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'meeting_type',
        'description',
        'agenda',
        'location',
        'scheduled_at',
        'ended_at',
        'quorum_required',
        'attendees_count',
        'minutes',
        'resolutions',
        'status',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'ended_at' => 'datetime',
        'quorum_required' => 'integer',
        'attendees_count' => 'integer',
        'resolutions' => 'array',
    ];

    protected $attributes = [
        'status' => 'scheduled',
        'meeting_type' => 'general',
        'attendees_count' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($meeting) {
            if (empty($meeting->uuid)) {
                $meeting->uuid = (string) Str::uuid();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(SaccoMember::class, 'sacco_meeting_attendances', 'meeting_id', 'member_id')
            ->withPivot('checked_in_at', 'proxy', 'proxy_name');
    }

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
        return $query->where('status', 'scheduled')->where('scheduled_at', '>', now());
    }

    public function getHasQuorumAttribute(): bool
    {
        return $this->quorum_required <= 0 || $this->attendees_count >= $this->quorum_required;
    }
}
