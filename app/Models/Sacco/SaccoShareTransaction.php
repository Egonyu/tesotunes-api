<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoShareTransaction extends Model
{
    use HasFactory;

    protected $table = 'sacco_share_transactions';

    protected $fillable = [
        'uuid',
        'transaction_code',
        'member_id',
        'share_id',
        'type',
        'shares_quantity',
        'price_per_share_ugx',
        'total_amount_ugx',
        'status',
        'notes',
    ];

    protected $casts = [
        'shares_quantity' => 'integer',
        'price_per_share_ugx' => 'decimal:2',
        'total_amount_ugx' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($txn) {
            if (empty($txn->uuid)) {
                $txn->uuid = (string) Str::uuid();
            }
            if (empty($txn->transaction_code)) {
                $txn->transaction_code = 'SHR'.now()->format('YmdHis').rand(1000, 9999);
            }
        });
    }

    // Relationships
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(SaccoShare::class, 'share_id');
    }

    // Scopes
    public function scopePurchases($query)
    {
        return $query->where('type', 'purchase');
    }

    public function scopeTransfers($query)
    {
        return $query->where('type', 'transfer');
    }
}
