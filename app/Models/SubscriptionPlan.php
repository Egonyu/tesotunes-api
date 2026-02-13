<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'interval',
        'interval_count',
        'trial_days',
        'is_active',
        'is_featured',
        'is_visible',
        'features',
        'limits',
        'metadata',
        'sort_order',
        'price_usd',
        'price_local',
        'duration_days',
        'region',
        'is_trial',
        'is_popular',
        'type',
        'max_downloads_per_day',
        'download_limit',
        'max_uploads_per_month',
        'max_audio_quality_kbps',
        'allows_offline',
        'ad_free',
    ];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_visible' => 'boolean',
        'is_trial' => 'boolean',
        'is_popular' => 'boolean',
        'allows_offline' => 'boolean',
        'ad_free' => 'boolean',
        'price' => 'decimal:2',
        'price_usd' => 'decimal:2',
        'price_local' => 'decimal:2',
        'trial_days' => 'integer',
        'duration_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByRegion($query, $region)
    {
        return $query->where('region', $region);
    }

    public function getFormattedPriceAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->price, 2);
    }

    public function getIntervalDisplayAttribute(): string
    {
        $interval = $this->interval;
        if ($this->interval_count > 1) {
            $interval = $this->interval_count . ' ' . str_plural($this->interval);
        }
        return $interval;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }

    public function getLimit(string $limitType): ?int
    {
        return $this->limits[$limitType] ?? null;
    }
}