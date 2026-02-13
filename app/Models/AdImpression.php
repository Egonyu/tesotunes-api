<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImpression extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_id',
        'user_id',
        'ip_address',
        'user_agent',
        'page_url',
        'device_type',
        'clicked',
        'clicked_at',
    ];

    protected $casts = [
        'clicked' => 'boolean',
        'clicked_at' => 'datetime',
    ];

    /**
     * Get the ad that owns this impression
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    /**
     * Get the user that viewed this impression
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
