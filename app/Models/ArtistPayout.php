<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ArtistPayout extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────
    const STATUS_PENDING    = 'pending';
    const STATUS_APPROVED   = 'approved';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_CANCELLED  = 'cancelled';

    // ── Method constants ──────────────────────────────────────────────────────
    const METHOD_MOBILE_MONEY  = 'mobile_money';
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHOD_PAYPAL        = 'paypal';
    const METHOD_ZENGAPAY      = 'zengapay';

    protected $fillable = [
        'artist_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'transaction_id',
        'payout_method',
        'currency',
        'phone_number',
        'account_number',
        'bank_name',
        'bank_code',
        'account_holder_name',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount'      => 'float',
        'fee_amount'  => 'float',
        'net_amount'  => 'float',
        'metadata'    => 'array',
        'approved_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at'         => 'datetime',
        'failed_at'            => 'datetime',
    ];

    // Protected from mass-assignment so financial fields are always set explicitly
    public float $amount     = 0.0;
    public float $fee_amount = 0.0;
    public float $net_amount = 0.0;
    public string $status    = self::STATUS_PENDING;

    // ── Relationships ─────────────────────────────────────────────────────────

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    // ── State checks ──────────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeRejected(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeRetried(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    // ── State transitions ─────────────────────────────────────────────────────

    public function markAsApproved(User $approver): void
    {
        $this->forceFill([
            'status'              => self::STATUS_APPROVED,
            'approved_by_user_id' => $approver->id,
            'approved_at'         => now(),
        ])->save();
    }

    public function markAsProcessing(): void
    {
        $this->forceFill([
            'status'                => self::STATUS_PROCESSING,
            'processing_started_at' => now(),
        ])->save();
    }

    public function markAsCompleted(array $data = []): void
    {
        $this->forceFill([
            'status'                  => self::STATUS_COMPLETED,
            'completed_at'            => now(),
            'external_transaction_id' => $data['external_transaction_id'] ?? $this->external_transaction_id,
            'provider_reference'      => $data['provider_reference'] ?? $this->provider_reference,
            'metadata'                => array_merge($this->metadata ?? [], $data['metadata'] ?? []),
        ])->save();
    }

    public function markAsFailed(string $reason, array $data = []): void
    {
        $this->forceFill([
            'status'         => self::STATUS_FAILED,
            'failure_reason' => $reason,
            'failed_at'      => now(),
            'metadata'       => array_merge($this->metadata ?? [], $data['metadata'] ?? []),
        ])->save();
    }

    public function markAsRejected(User $rejector, string $reason): void
    {
        $this->forceFill([
            'status'              => self::STATUS_REJECTED,
            'approved_by_user_id' => $rejector->id,
            'failure_reason'      => $reason,
            'rejected_at'         => now(),
        ])->save();
    }

    public function markAsCancelled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
        ])->save();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateTransactionId(): string
    {
        return 'PAY-' . strtoupper(Str::random(12));
    }
}
