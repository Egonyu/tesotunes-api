<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class RoyaltySplit extends Model
{
    use HasFactory;

    protected $table = 'royalty_splits';

    protected $fillable = [
        'song_id',
        'user_id',
        'collaborator_name',
        'collaborator_email',
        'role',
        'role_description',
        'split_percentage',
        'split_type',
        'payment_method',
        'payment_details',
        'is_verified',
        'has_agreed',
        'agreed_at',
        'agreement_signature',
        'notes',
        'recipient_id',
        'recipient_role',
        'percentage',
        'fixed_amount',
        'applies_to_streaming',
        'applies_to_downloads',
        'applies_to_physical',
        'applies_to_sync',
        'applies_to_performance',
        'applies_to_mechanical',
        'territorial_scope',
        'worldwide',
        'effective_from',
        'effective_until',
        'minimum_plays',
        'minimum_revenue',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'recipient_payout_info',
        'recipient_status',
        'agreement_reference',
        'agreement_type',
        'tax_withholding_required',
        'tax_withholding_rate',
        'tax_form_type',
        'payout_frequency',
        'minimum_payout_amount',
        'auto_payout_enabled',
        'last_payout_at',
        'total_paid_out',
        'pending_payout',
        'status',
        'approved_at',
        'approved_by',
        'notes',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'applies_to_streaming' => 'boolean',
        'applies_to_downloads' => 'boolean',
        'applies_to_physical' => 'boolean',
        'applies_to_sync' => 'boolean',
        'applies_to_performance' => 'boolean',
        'applies_to_mechanical' => 'boolean',
        'territorial_scope' => 'array',
        'worldwide' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'minimum_plays' => 'integer',
        'minimum_revenue' => 'decimal:2',
        'recipient_payout_info' => 'array',
        'tax_withholding_required' => 'boolean',
        'tax_withholding_rate' => 'decimal:2',
        'auto_payout_enabled' => 'boolean',
        'last_payout_at' => 'datetime',
        'total_paid_out' => 'decimal:2',
        'pending_payout' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // Relationships
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('effective_from', '<=', now())
                    ->where(function($q) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', now());
                    });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeByRecipient($query, int $recipientId)
    {
        return $query->where('recipient_id', $recipientId);
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('recipient_role', $role);
    }

    public function scopeByRevenueType($query, string $type)
    {
        return $query->where("applies_to_{$type}", true);
    }

    public function scopeByTerritory($query, string $territory)
    {
        return $query->where(function($q) use ($territory) {
            $q->where('worldwide', true)
              ->orWhereJsonContains('territorial_scope', $territory);
        });
    }

    public function scopeDueForPayout($query)
    {
        return $query->where('auto_payout_enabled', true)
                    ->where('pending_payout', '>=', function($q) {
                        $q->selectRaw('minimum_payout_amount');
                    });
    }

    public function scopeOverduePayouts($query, string $frequency = null)
    {
        $query->where('pending_payout', '>', 0);

        if ($frequency) {
            $lastPayoutThreshold = match($frequency) {
                'realtime' => now()->subMinutes(5),
                'daily' => now()->subDay(),
                'weekly' => now()->subWeek(),
                'monthly' => now()->subMonth(),
                'quarterly' => now()->subMonths(3),
                default => now()->subMonth()
            };

            $query->where(function($q) use ($lastPayoutThreshold) {
                $q->whereNull('last_payout_at')
                  ->orWhere('last_payout_at', '<', $lastPayoutThreshold);
            });
        }

        return $query;
    }

    // Helper Methods
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();
        $started = $this->effective_from <= $now;
        $notExpired = !$this->effective_until || $this->effective_until >= $now;

        return $started && $notExpired;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->effective_until && $this->effective_until < now();
    }

    public function appliesToRevenueType(string $type): bool
    {
        return match($type) {
            'streaming' => $this->applies_to_streaming,
            'downloads' => $this->applies_to_downloads,
            'physical' => $this->applies_to_physical,
            'sync' => $this->applies_to_sync,
            'performance' => $this->applies_to_performance,
            'mechanical' => $this->applies_to_mechanical,
            default => false
        };
    }

    public function appliesToTerritory(string $territory): bool
    {
        if ($this->worldwide) {
            return true;
        }

        return in_array($territory, $this->territorial_scope ?? []);
    }

    public function isDueForPayout(): bool
    {
        return $this->auto_payout_enabled &&
               $this->pending_payout >= $this->minimum_payout_amount;
    }

    public function isOverduePayout(): bool
    {
        if ($this->pending_payout <= 0) {
            return false;
        }

        $threshold = match($this->payout_frequency) {
            'realtime' => now()->subMinutes(5),
            'daily' => now()->subDay(),
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            'quarterly' => now()->subMonths(3),
            default => now()->subMonth()
        };

        return !$this->last_payout_at || $this->last_payout_at < $threshold;
    }

    public function calculateSplitAmount(float $totalRevenue, int $plays = 0): float
    {
        // Check minimum thresholds
        if ($plays < $this->minimum_plays || $totalRevenue < $this->minimum_revenue) {
            return 0.0;
        }

        return match($this->split_type) {
            'percentage' => $totalRevenue * ($this->percentage / 100),
            'fixed' => min($this->fixed_amount * $plays, $totalRevenue),
            'hybrid' => min(
                $totalRevenue * ($this->percentage / 100),
                $this->fixed_amount * $plays
            ),
            default => 0.0
        };
    }

    public function calculateTaxWithholding(float $amount): float
    {
        if (!$this->tax_withholding_required) {
            return 0.0;
        }

        return $amount * ($this->tax_withholding_rate / 100);
    }

    public function getNetPayoutAmount(float $grossAmount): float
    {
        $taxWithholding = $this->calculateTaxWithholding($grossAmount);
        return $grossAmount - $taxWithholding;
    }

    public function getRoleDisplayAttribute(): string
    {
        return match($this->recipient_role) {
            'artist' => 'Primary Artist',
            'songwriter' => 'Songwriter',
            'producer' => 'Producer',
            'feature' => 'Featured Artist',
            'mixer' => 'Mixing Engineer',
            'mastering' => 'Mastering Engineer',
            'composer' => 'Composer',
            'lyricist' => 'Lyricist',
            'publisher' => 'Publisher',
            'label' => 'Record Label',
            default => ucfirst($this->recipient_role)
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        if ($this->isExpired()) {
            return '⏰ Expired';
        }

        return match($this->status) {
            'active' => '✅ Active',
            'pending_approval' => '⏳ Pending',
            'disputed' => '⚠️ Disputed',
            'suspended' => '⏸️ Suspended',
            'terminated' => '❌ Terminated',
            default => '❓ Unknown'
        };
    }

    public function getSplitTypeDisplayAttribute(): string
    {
        return match($this->split_type) {
            'percentage' => $this->percentage . '% of revenue',
            'fixed' => 'UGX ' . number_format($this->fixed_amount, 0) . ' per play',
            'hybrid' => $this->percentage . '% or UGX ' . number_format($this->fixed_amount, 0) . ' per play (whichever is lower)',
            default => 'Unknown split type'
        };
    }

    public function getPayoutFrequencyDisplayAttribute(): string
    {
        return match($this->payout_frequency) {
            'realtime' => 'Real-time',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            default => ucfirst($this->payout_frequency)
        };
    }

    public function approve(User $approver): void
    {
        $this->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $approver->id,
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
            'effective_until' => now(),
            'notes' => $reason ? "Terminated: {$reason}" : 'Terminated',
        ]);
    }

    public function addPendingRevenue(float $amount): void
    {
        $this->increment('pending_payout', $amount);
    }

    public function processPayout(float $amount): void
    {
        $this->update([
            'pending_payout' => max(0, $this->pending_payout - $amount),
            'total_paid_out' => $this->total_paid_out + $amount,
            'last_payout_at' => now(),
        ]);
    }

    public function updatePayoutInfo(array $payoutInfo): void
    {
        $this->update(['recipient_payout_info' => $payoutInfo]);
    }

    public function extendContract(Carbon $newEndDate): void
    {
        $this->update(['effective_until' => $newEndDate]);
    }

    public function updateSplitPercentage(float $newPercentage): void
    {
        if (!self::validateTotalPercentage($this->song_id, $newPercentage, $this->id)) {
            throw new \Exception('Total split percentages would exceed 100%');
        }

        $this->update(['percentage' => $newPercentage]);
    }

    // Validation methods
    public static function validateTotalPercentage(int $songId, float $newPercentage, int $excludeId = null): bool
    {
        $query = self::where('song_id', $songId)
                    ->where('status', 'active')
                    ->where('split_type', 'percentage');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $currentTotal = $query->sum('percentage');
        return ($currentTotal + $newPercentage) <= 100.0;
    }

    public static function getTotalSplitPercentage(int $songId): float
    {
        return self::where('song_id', $songId)
                  ->where('status', 'active')
                  ->where('split_type', 'percentage')
                  ->sum('percentage');
    }

    public static function getAvailablePercentage(int $songId): float
    {
        return 100.0 - self::getTotalSplitPercentage($songId);
    }

    // Static creation methods
    public static function createForArtist(Song $song, User $artist, float $percentage = 100.0): self
    {
        return self::create([
            'song_id' => $song->id,
            'recipient_id' => $artist->id,
            'recipient_role' => 'artist',
            'percentage' => $percentage,
            'split_type' => 'percentage',
            'applies_to_streaming' => true,
            'applies_to_downloads' => true,
            'applies_to_physical' => true,
            'applies_to_performance' => true,
            'worldwide' => true,
            'effective_from' => now(),
            'recipient_name' => $artist->name,
            'recipient_email' => $artist->email,
            'payout_frequency' => 'monthly',
            'minimum_payout_amount' => 10000, // 10k UGX
            'auto_payout_enabled' => true,
            'status' => 'active',
        ]);
    }

    public static function createForCollaborator(Song $song, array $collaboratorData): self
    {
        return self::create(array_merge([
            'song_id' => $song->id,
            'split_type' => 'percentage',
            'applies_to_streaming' => true,
            'applies_to_downloads' => true,
            'worldwide' => true,
            'effective_from' => now(),
            'payout_frequency' => 'monthly',
            'minimum_payout_amount' => 5000, // 5k UGX
            'auto_payout_enabled' => false, // Require manual approval
            'status' => 'pending_approval',
        ], $collaboratorData));
    }
}