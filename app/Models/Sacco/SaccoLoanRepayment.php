<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoLoanRepayment extends Model
{
    use HasFactory;

    protected $table = 'sacco_loan_repayments';

    protected $fillable = [
        'uuid',
        'payment_code',
        'loan_id',
        'member_id',
        'amount_ugx',
        'principal_paid_ugx',
        'interest_paid_ugx',
        'penalty_paid_ugx',
        'payment_date',
        'due_date',
        'is_early_payment',
        'is_late_payment',
        'payment_method',
        'reference_number',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'principal_paid_ugx' => 'decimal:2',
        'interest_paid_ugx' => 'decimal:2',
        'penalty_paid_ugx' => 'decimal:2',
        'payment_date' => 'datetime',
        'due_date' => 'date',
        'is_early_payment' => 'boolean',
        'is_late_payment' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($repayment) {
            if (empty($repayment->uuid)) {
                $repayment->uuid = (string) Str::uuid();
            }
            if (empty($repayment->payment_code)) {
                $repayment->payment_code = 'REP' . now()->format('YmdHis') . rand(1000, 9999);
            }
        });
    }

    // Relationships
    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
