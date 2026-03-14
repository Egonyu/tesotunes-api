<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoLoanGuarantor extends Model
{
    use HasFactory;

    protected $table = 'sacco_guarantors';

    protected $fillable = [
        'loan_id',
        'guarantor_member_id',
        'guaranteed_amount',
        'status',
        'accepted_at',
        'declined_at',
        'decline_reason',
    ];

    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    public function guarantorMember(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'guarantor_member_id');
    }
}
