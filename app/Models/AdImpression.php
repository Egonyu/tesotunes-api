<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImpression extends Model
{
    use HasFactory;

    // Impressions are append-only; no updated_at column in migration
    public const UPDATED_AT = null;

    protected $fillable = [
        'ad_id',
        'placement_key',
        'user_id',
        'ip_address',
        'user_agent',
        'device_type',
        'page_url',
        'clicked',
        'clicked_at',
    ];

    protected $casts = [
        'clicked' => 'boolean',
        'clicked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
