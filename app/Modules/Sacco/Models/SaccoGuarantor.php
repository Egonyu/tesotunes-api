<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoGuarantor extends Model
{
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

    /**
     * Get the loan
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    /**
     * Get the guarantor member
     */
    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'guarantor_member_id');
    }
}
