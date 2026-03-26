<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTicketChannelAllocation extends Model
{
    use HasFactory;

    public const CHANNEL_EXTERNAL = 'external';

    protected $fillable = [
        'uuid',
        'event_id',
        'ticket_id',
        'logged_by_user_id',
        'channel',
        'channel_label',
        'quantity',
        'notes',
        'released_at',
        'released_by_user_id',
        'release_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'released_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'ticket_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }

    public function release(?User $user = null, ?string $reason = null): void
    {
        $this->forceFill([
            'released_at' => now(),
            'released_by_user_id' => $user?->id,
            'release_reason' => $reason,
        ])->save();
    }
}
