<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoDividendDistribution extends Model
{
    protected $fillable = [
        'dividend_id',
        'member_id',
        'shares_held',
        'gross_amount',
        'withholding_tax',
        'net_amount',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'shares_held' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'withholding_tax' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * Get the dividend
     */
    public function dividend(): BelongsTo
    {
        return $this->belongsTo(SaccoDividend::class, 'dividend_id');
    }

    /**
     * Get the member
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
