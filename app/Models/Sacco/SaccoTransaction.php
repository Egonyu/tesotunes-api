<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SaccoTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'member_id',
        'transaction_reference',
        'transaction_type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'notes',
        'processed_by',
        'transaction_date',
        'source_type',
        'source_id',
    ];

    // Prevent any updates to transactions (immutable)
    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    protected $attributes = [
        'transaction_date' => null,
    ];

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeByMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeDeposits($query)
    {
        return $query->where('transaction_type', 'deposit');
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('transaction_type', 'withdrawal');
    }

    public function scopeRecentFirst($query)
    {
        return $query->orderBy('transaction_date', 'desc');
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    // Accessors
    public function getIsDebitAttribute(): bool
    {
        return in_array($this->transaction_type, [
            'withdrawal',
            'transfer', // from the source account perspective
            'fee',
        ]);
    }

    public function getIsCreditAttribute(): bool
    {
        return in_array($this->transaction_type, [
            'deposit',
            'loan_disbursement',
            'dividend',
            'interest',
        ]);
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'UGX ' . number_format($this->amount, 2);
    }

    public function getTransactionTypeDisplayAttribute(): string
    {
        return ucwords(str_replace('_', ' ', $this->transaction_type));
    }

    // Prevent updates and deletes (transactions are immutable)
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_reference)) {
                $transaction->transaction_reference = self::generateReference();
            }
            
            if (empty($transaction->transaction_date)) {
                $transaction->transaction_date = now();
            }
        });

        static::updating(function ($transaction) {
            throw new \Exception('Transactions cannot be updated once created');
        });

        static::deleting(function ($transaction) {
            throw new \Exception('Transactions cannot be deleted');
        });
    }

    protected static function generateReference(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        return "TXN-{$date}-{$random}";
    }
}
