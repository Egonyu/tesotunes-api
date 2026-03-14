<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoMeetingAttendance extends Model
{
    use HasFactory;

    protected $table = 'sacco_meeting_attendances';

    protected $fillable = [
        'meeting_id',
        'member_id',
        'checked_in_at',
        'proxy',
        'proxy_name',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'proxy' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(SaccoMeeting::class, 'meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
