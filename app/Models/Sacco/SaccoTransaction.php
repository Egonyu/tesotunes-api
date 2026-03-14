<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoTransaction extends Model
{
    use HasFactory;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_LOAN_DISBURSEMENT = 'loan_disbursement';

    public const TYPE_LOAN_REPAYMENT = 'loan_repayment';

    public const TYPE_DIVIDEND = 'dividend';

    public const TYPE_INTEREST = 'interest';

    public const TYPE_FEE = 'fee';

    protected $table = 'sacco_transactions';

    protected $fillable = [
        'uuid',
        'account_id',
        'member_id',
        'loan_id',
        'transaction_number',
        'transaction_reference',
        'transaction_type',
        'amount',
        'balance_before',
        'balance_after',
        'payment_method',
        'reference',
        'status',
        'description',
        'notes',
        'processed_by',
        'processed_at',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'processed_at' => 'datetime',
        'transaction_date' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'completed',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeDeposits($query)
    {
        return $query->where('transaction_type', self::TYPE_DEPOSIT);
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('transaction_type', self::TYPE_WITHDRAWAL);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('transaction_date', '>=', now()->subDays($days));
    }

    public function getIsDebitAttribute(): bool
    {
        return in_array($this->transaction_type, [
            self::TYPE_WITHDRAWAL,
            self::TYPE_LOAN_DISBURSEMENT,
            self::TYPE_FEE,
        ], true);
    }

    public function getIsCreditAttribute(): bool
    {
        return ! $this->is_debit;
    }

    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->is_debit ? '-' : '+';

        return $prefix.'UGX '.number_format((float) $this->amount, 2);
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }

            if (empty($transaction->transaction_reference)) {
                $transaction->transaction_reference = 'SACT-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
            }

            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = strtoupper(Str::random(12));
            }

            if (empty($transaction->transaction_date)) {
                $transaction->transaction_date = now();
            }

            if (empty($transaction->processed_at)) {
                $transaction->processed_at = now();
            }
        });
    }
}
