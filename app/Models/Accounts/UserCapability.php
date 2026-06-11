<?php

namespace App\Models\Accounts;

use App\Enums\Capability;
use App\Enums\CapabilityStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A single capability grant on a user account.
 * Lifecycle transitions happen exclusively through CapabilityService.
 */
class UserCapability extends Model
{
    protected $fillable = [
        'user_id',
        'capability',
        'application',
        'metadata',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserCapability $grant) {
            $grant->uuid = $grant->uuid ?: (string) Str::uuid();
            $grant->applied_at = $grant->applied_at ?: now();
        });
    }

    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'status' => CapabilityStatus::class,
            'applied_at' => 'datetime',
            'granted_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'application' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function profile(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeGranted(Builder $query): Builder
    {
        return $query->where('status', CapabilityStatus::Granted);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CapabilityStatus::Pending);
    }

    public function scopeOfCapability(Builder $query, Capability $capability): Builder
    {
        return $query->where('capability', $capability);
    }
}
