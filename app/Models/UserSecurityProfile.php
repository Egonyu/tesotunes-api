<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSecurityProfile extends Model
{
    protected $fillable = [
        'user_id',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'last_security_reviewed_at',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
        'last_security_reviewed_at' => 'datetime',
    ];

    public static function createDefault(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            [
                'two_factor_enabled' => $user->two_factor_enabled ?? false,
                'two_factor_secret' => $user->two_factor_secret,
                'two_factor_recovery_codes' => $user->two_factor_recovery_codes,
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
            ]
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
