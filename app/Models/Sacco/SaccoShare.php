<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoShare extends Model
{
    use HasFactory;

    protected $table = 'sacco_shares';

    protected $fillable = [
        'uuid',
        'member_id',
        'total_shares',
        'share_value_ugx',
        'total_value_ugx',
        'last_purchase_at',
    ];

    protected $casts = [
        'total_shares' => 'integer',
        'share_value_ugx' => 'decimal:2',
        'total_value_ugx' => 'decimal:2',
        'last_purchase_at' => 'datetime',
    ];

    protected $attributes = [
        'total_shares' => 0,
        'total_value_ugx' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($share) {
            if (empty($share->uuid)) {
                $share->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoShareTransaction::class, 'share_id');
    }
}
