<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoBoardMeetingAttendance extends Model
{
    use HasFactory;

    protected $table = 'sacco_board_meeting_attendance';

    protected $fillable = [
        'meeting_id',
        'board_member_id',
        'status',
        'notes',
    ];

    protected $attributes = [
        'status' => 'absent',
    ];

    // Relationships
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(SaccoBoardMeeting::class, 'meeting_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(SaccoBoardMember::class, 'board_member_id');
    }

    // Scopes
    public function scopePresent($query)
    {
        return $query->where('status', 'present');
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', 'absent');
    }

    public function scopeExcused($query)
    {
        return $query->where('status', 'excused');
    }

    // Accessors
    public function getWasPresentAttribute(): bool
    {
        return $this->status === 'present';
    }
}
