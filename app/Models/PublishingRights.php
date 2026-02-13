<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class PublishingRights extends Model
{
    use HasFactory;

    protected $table = 'publishing_rights';

    protected $fillable = [
        'song_id',
        'owner_id',
        'rights_type',
        'ownership_percentage',
        'royalty_split_percentage',
        'rights_holder_name',
        'rights_holder_type',
        'performing_rights_organization',
        'pro_member_number',
        'rights_description',
        'rights_start_date',
        'rights_end_date',
        'territorial_scope',
        'exclusive_rights',
        'contract_reference',
        'contract_type',
        'contract_terms',
        'documentation_url',
        'collect_royalties',
        'minimum_payout_threshold',
        'payout_frequency',
        'payout_method',
        'payout_details',
        'status',
        'activated_at',
        'created_by_type',
        'created_by_id',
        'notes',
    ];

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'royalty_split_percentage' => 'decimal:2',
        'rights_start_date' => 'date',
        'rights_end_date' => 'date',
        'territorial_scope' => 'array',
        'exclusive_rights' => 'boolean',
        'contract_terms' => 'array',
        'collect_royalties' => 'boolean',
        'minimum_payout_threshold' => 'decimal:2',
        'payout_details' => 'array',
        'activated_at' => 'datetime',
    ];

    // Relationships
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('rights_start_date', '<=', now())
                    ->where(function($q) {
                        $q->whereNull('rights_end_date')
                          ->orWhere('rights_end_date', '>=', now());
                    });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDisputed($query)
    {
        return $query->where('status', 'disputed');
    }

    public function scopeByRightsType($query, string $type)
    {
        return $query->where('rights_type', $type);
    }

    public function scopeByOwner($query, int $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeByTerritory($query, string $territory)
    {
        return $query->where(function($q) use ($territory) {
            $q->whereNull('territorial_scope')
              ->orWhereJsonContains('territorial_scope', $territory)
              ->orWhereJsonContains('territorial_scope', 'Global');
        });
    }

    public function scopeCollectingRoyalties($query)
    {
        return $query->where('collect_royalties', true);
    }

    public function scopeExclusive($query)
    {
        return $query->where('exclusive_rights', true);
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('rights_end_date')
                    ->where('rights_end_date', '<=', now()->addDays($days))
                    ->where('rights_end_date', '>=', now());
    }

    // Helper Methods
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();
        $started = $this->rights_start_date <= $now;
        $notExpired = !$this->rights_end_date || $this->rights_end_date >= $now;

        return $started && $notExpired;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function isExpired(): bool
    {
        return $this->rights_end_date && $this->rights_end_date < now();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->rights_end_date) {
            return false;
        }

        return $this->rights_end_date <= now()->addDays($days) &&
               $this->rights_end_date >= now();
    }

    public function appliesToTerritory(string $territory): bool
    {
        if (!$this->territorial_scope) {
            return true; // No restrictions = worldwide
        }

        return in_array($territory, $this->territorial_scope) ||
               in_array('Global', $this->territorial_scope);
    }

    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->rights_end_date) {
            return null;
        }

        return now()->diffInDays($this->rights_end_date, false);
    }

    public function getRightsTypeDisplayAttribute(): string
    {
        return match($this->rights_type) {
            'mechanical' => 'Mechanical Rights',
            'performance' => 'Performance Rights',
            'synchronization' => 'Sync Rights',
            'print' => 'Print Rights',
            'digital' => 'Digital Rights',
            default => ucfirst($this->rights_type) . ' Rights'
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        if ($this->isExpired()) {
            return '⏰ Expired';
        }

        return match($this->status) {
            'active' => '✅ Active',
            'pending' => '⏳ Pending',
            'disputed' => '⚠️ Disputed',
            'suspended' => '⏸️ Suspended',
            'terminated' => '❌ Terminated',
            default => '❓ Unknown'
        };
    }

    public function getPayoutMethodDisplayAttribute(): string
    {
        return match($this->payout_method) {
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Bank Transfer',
            'check' => 'Check',
            'crypto' => 'Cryptocurrency',
            default => ucfirst($this->payout_method)
        };
    }

    public function activate(): void
    {
        $this->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    public function suspend(string $reason = null): void
    {
        $this->update([
            'status' => 'suspended',
            'notes' => $reason ? "Suspended: {$reason}" : 'Suspended',
        ]);
    }

    public function dispute(string $reason): void
    {
        $this->update([
            'status' => 'disputed',
            'notes' => "Disputed: {$reason}",
        ]);
    }

    public function terminate(string $reason = null): void
    {
        $this->update([
            'status' => 'terminated',
            'rights_end_date' => now(),
            'notes' => $reason ? "Terminated: {$reason}" : 'Terminated',
        ]);
    }

    public function extendRights(Carbon $newEndDate): void
    {
        $this->update(['rights_end_date' => $newEndDate]);
    }

    public function updateOwnership(float $newPercentage, float $newRoyaltySplit = null): void
    {
        $this->update([
            'ownership_percentage' => $newPercentage,
            'royalty_split_percentage' => $newRoyaltySplit ?? $newPercentage,
        ]);
    }

    public function addTerritory(string $territory): void
    {
        $scope = $this->territorial_scope ?? [];
        if (!in_array($territory, $scope)) {
            $scope[] = $territory;
            $this->update(['territorial_scope' => $scope]);
        }
    }

    public function removeTerritory(string $territory): void
    {
        $scope = $this->territorial_scope ?? [];
        $scope = array_diff($scope, [$territory]);
        $this->update(['territorial_scope' => array_values($scope)]);
    }

    // Validation methods
    public static function validateOwnershipPercentage(int $songId, string $rightsType, float $newPercentage, int $excludeId = null): bool
    {
        $query = self::where('song_id', $songId)
                    ->where('rights_type', $rightsType)
                    ->where('status', 'active');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $currentTotal = $query->sum('ownership_percentage');
        return ($currentTotal + $newPercentage) <= 100.0;
    }

    public static function getTotalOwnership(int $songId, string $rightsType): float
    {
        return self::where('song_id', $songId)
                  ->where('rights_type', $rightsType)
                  ->where('status', 'active')
                  ->sum('ownership_percentage');
    }

    public static function getAvailableOwnership(int $songId, string $rightsType): float
    {
        return 100.0 - self::getTotalOwnership($songId, $rightsType);
    }

    // Static creation methods
    public static function createForWriter(Song $song, User $writer, float $percentage = 100.0): self
    {
        return self::create([
            'song_id' => $song->id,
            'owner_id' => $writer->id,
            'rights_type' => 'mechanical',
            'ownership_percentage' => $percentage,
            'royalty_split_percentage' => $percentage,
            'rights_holder_name' => $writer->name,
            'rights_holder_type' => 'writer',
            'rights_start_date' => now(),
            'collect_royalties' => true,
            'status' => 'active',
            'created_by_type' => 'system',
        ]);
    }

    public static function createForPublisher(Song $song, User $publisher, float $percentage, array $territories = ['Global']): self
    {
        return self::create([
            'song_id' => $song->id,
            'owner_id' => $publisher->id,
            'rights_type' => 'performance',
            'ownership_percentage' => $percentage,
            'royalty_split_percentage' => $percentage,
            'rights_holder_name' => $publisher->name,
            'rights_holder_type' => 'publisher',
            'territorial_scope' => $territories,
            'rights_start_date' => now(),
            'collect_royalties' => true,
            'status' => 'pending',
            'created_by_type' => 'system',
        ]);
    }
}