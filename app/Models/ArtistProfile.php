<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtistProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'artist_id',
        'stage_name',
        'real_name',
        'nin_number',
        'verification_status',
        'verification_documents',
        'verified_at',
        'bio',
        'website',
        'social_links',
        'manager_name',
        'manager_contact',
        'genres',
        'languages',
        'record_label',
        'publishing_company',
        'region',
        'district',
        'career_stage',
        'mobile_money_provider',
        'mobile_money_number',
        'bank_name',
        'bank_account',
        'payout_method',
        'minimum_payout',
        'total_credits_earned',
        'total_money_earned',
        'money_payout_enabled',
        'money_payout_unlocked_at',
        'auto_distribute',
        'distribution_preferences',
        'distribution_fee_percentage',
        'public_stats',
        'detailed_analytics',
        'is_active',
        'last_login_at',
        'profile_completed',
    ];

    protected $casts = [
        'verification_documents' => 'array',
        'verified_at' => 'datetime',
        'social_links' => 'array',
        'genres' => 'array',
        'languages' => 'array',
        'distribution_preferences' => 'array',
        'total_credits_earned' => 'decimal:2',
        'total_money_earned' => 'decimal:2',
        'minimum_payout' => 'decimal:2',
        'distribution_fee_percentage' => 'decimal:2',
        'money_payout_enabled' => 'boolean',
        'money_payout_unlocked_at' => 'datetime',
        'auto_distribute' => 'boolean',
        'public_stats' => 'boolean',
        'detailed_analytics' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'profile_completed' => 'boolean',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(ArtistRevenue::class, 'artist_id', 'artist_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ArtistPayout::class, 'artist_id', 'artist_id');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(SongDistribution::class, 'artist_id', 'artist_id');
    }

    // Helper Methods
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function canReceiveMoneyPayouts(): bool
    {
        return $this->money_payout_enabled && $this->isVerified();
    }

    public function checkMoneyPayoutEligibility(): bool
    {
        // Unlock money payouts after earning 10,000 credits or UGX 50,000
        $creditThreshold = 10000;
        $moneyThreshold = 50000;

        if ($this->total_credits_earned >= $creditThreshold || $this->total_money_earned >= $moneyThreshold) {
            if (!$this->money_payout_enabled) {
                $this->update([
                    'money_payout_enabled' => true,
                    'money_payout_unlocked_at' => now(),
                ]);
            }
            return true;
        }

        return false;
    }

    public function getTotalEarningsAttribute(): float
    {
        return $this->total_credits_earned + $this->total_money_earned;
    }

    public function getCareerLevelAttribute(): string
    {
        $totalEarnings = $this->getTotalEarningsAttribute();

        if ($totalEarnings >= 500000) return 'Mainstream';
        if ($totalEarnings >= 100000) return 'Established';
        if ($totalEarnings >= 25000) return 'Developing';
        return 'Emerging';
    }

    public function getVerificationBadgeAttribute(): string
    {
        return match($this->verification_status) {
            'verified' => '✅ Verified',
            'pending' => '⏳ Pending',
            'rejected' => '❌ Rejected',
            default => '⚪ Unverified'
        };
    }

    public function getPayoutMethodDisplayAttribute(): string
    {
        return match($this->payout_method) {
            'mobile_money' => $this->mobile_money_provider . ' Mobile Money',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash Pickup',
            default => 'Not Set'
        };
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    public function scopeByRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    public function scopeByCareerStage($query, string $stage)
    {
        return $query->where('career_stage', $stage);
    }

    public function scopeMoneyEligible($query)
    {
        return $query->where('money_payout_enabled', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
