<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignPledge extends Model
{
    protected $table = 'campaign_pledges';

    protected $fillable = [
        'uuid',
        'campaign_id',
        'user_id',
        'amount',
        'message',
        'is_anonymous',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_anonymous' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $pledge) {
            if (empty($pledge->uuid)) {
                $pledge->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
