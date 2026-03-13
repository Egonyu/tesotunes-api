<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReferral extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'referrer_id',
        'referral_count',
        'referred_at',
    ];

    protected $casts = [
        'referral_count' => 'integer',
        'referred_at' => 'datetime',
    ];

    public static function createDefault(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            [
                'referral_code' => $user->referral_code,
                'referrer_id' => $user->referrer_id,
                'referral_count' => $user->referral_count ?? 0,
                'referred_at' => $user->referred_at,
            ]
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
