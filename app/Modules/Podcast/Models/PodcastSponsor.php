<?php

namespace App\Modules\Podcast\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PodcastSponsor extends Model
{
    use HasFactory;

    protected $fillable = [
        'podcast_id',
        'name',
        'logo_url',
        'website_url',
        'contact_email',
        'contract_start_date',
        'contract_end_date',
        'monthly_rate',
        'ad_type',
        'ad_script',
        'ad_audio_file',
        'status',
    ];

    protected $casts = [
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'monthly_rate' => 'decimal:2',
    ];

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\Podcast::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(\App\Modules\Podcast\Models\PodcastEpisode::class, 'sponsor_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' 
            && ($this->contract_end_date === null || $this->contract_end_date->isFuture());
    }
}
