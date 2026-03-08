<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoGoalTransaction extends Model
{
    protected $table = 'sacco_goal_transactions';

    protected $fillable = [
        'uuid',
        'goal_id',
        'member_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'payment_method',
        'transaction_reference',
        'notes',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'completed',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tx) {
            if (empty($tx->uuid)) {
                $tx->uuid = (string) Str::uuid();
            }
        });
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(SaccoGoal::class, 'goal_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
