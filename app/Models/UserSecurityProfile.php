<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class UserSecurityProfile extends Model
{
    private static ?bool $hasTwoFactorConfirmedAtColumn = null;

    private static ?bool $hasLastSecurityReviewedAtColumn = null;

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

    /**
     * Build a schema-safe attribute payload for user_security_profiles.
     */
    public static function payloadFromUser(User $user): array
    {
        $payload = [
            'two_factor_enabled' => (bool) ($user->two_factor_enabled ?? false),
            'two_factor_secret' => $user->two_factor_secret,
            'two_factor_recovery_codes' => static::normalizeRecoveryCodesForStorage($user->two_factor_recovery_codes),
            'last_security_reviewed_at' => now(),
        ];

        if (static::hasTwoFactorConfirmedAtColumn()) {
            $payload['two_factor_confirmed_at'] = $user->two_factor_confirmed_at;
        }

        if (! static::hasLastSecurityReviewedAtColumn()) {
            unset($payload['last_security_reviewed_at']);
        }

        return $payload;
    }

    private static function normalizeRecoveryCodesForStorage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return json_encode(array_values($value));
        }

        if (is_string($value)) {
            json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $value : json_encode([$value]);
        }

        return json_encode([$value]);
    }

    private static function hasTwoFactorConfirmedAtColumn(): bool
    {
        if (static::$hasTwoFactorConfirmedAtColumn === null) {
            static::$hasTwoFactorConfirmedAtColumn = Schema::hasColumn('user_security_profiles', 'two_factor_confirmed_at');
        }

        return static::$hasTwoFactorConfirmedAtColumn;
    }

    private static function hasLastSecurityReviewedAtColumn(): bool
    {
        if (static::$hasLastSecurityReviewedAtColumn === null) {
            static::$hasLastSecurityReviewedAtColumn = Schema::hasColumn('user_security_profiles', 'last_security_reviewed_at');
        }

        return static::$hasLastSecurityReviewedAtColumn;
    }

    public static function createDefault(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            static::payloadFromUser($user)
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
