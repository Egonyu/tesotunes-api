<?php

namespace App\Models\Modules\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'poll_id',
        'user_id',
        'session_token',
        'ip_address',
        'is_complete',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_complete' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PollAnswer::class, 'response_id');
    }

    // ── State ─────────────────────────────────────────────────────

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function complete(): void
    {
        $this->update([
            'is_complete' => true,
            'completed_at' => now(),
        ]);
    }
}
