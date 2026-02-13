<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoDividend extends Model
{
    protected $fillable = [
        'year',
        'total_profit',
        'distributable_amount',
        'distribution_percentage',
        'total_shares',
        'rate_per_share',
        'withholding_tax_percentage',
        'status',
        'calculated_at',
        'calculated_by',
        'approved_at',
        'approved_by',
        'distributed_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'total_profit' => 'decimal:2',
        'distributable_amount' => 'decimal:2',
        'distribution_percentage' => 'decimal:2',
        'total_shares' => 'decimal:2',
        'rate_per_share' => 'decimal:2',
        'withholding_tax_percentage' => 'decimal:2',
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'distributed_at' => 'datetime',
    ];

    /**
     * Get dividend distributions
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(SaccoDividendDistribution::class, 'dividend_id');
    }

    /**
     * Get the user who calculated the dividend
     */
    public function calculatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'calculated_by');
    }

    /**
     * Get the user who approved the dividend
     */
    public function approvedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
