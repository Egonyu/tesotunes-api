<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventFunnelTouchpoint extends Model
{
    use HasFactory;

    public const STAGE_VISIT = 'visit';

    public const STAGE_CHECKOUT_START = 'checkout_start';

    protected $fillable = [
        'event_id',
        'stage',
        'session_key',
        'source_label',
        'source',
        'channel',
        'campaign_code',
        'referral_code',
        'promoter_code',
        'touch_date',
        'landing_page',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
