<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SaccoMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'member_number',
        'joined_at',
        'status',
        'member_type',
        'id_number',
        'id_type',
        'date_of_birth',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'date_of_birth' => 'date',
    ];

    protected $attributes = [
        'status' => 'active',
        'member_type' => 'regular',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shares(): HasOne
    {
        return $this->hasOne(SaccoShare::class, 'member_id');
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SaccoSavingsAccount::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'member_id');
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(SaccoPollVote::class, 'member_id');
    }

    public function meetingAttendances(): HasMany
    {
        return $this->hasMany(SaccoMeetingAttendance::class, 'member_id');
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(SaccoMemberDividend::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoSavingsTransaction::class, 'member_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeResigned($query)
    {
        return $query->where('status', 'resigned');
    }

    public function scopeByMemberNumber($query, string $memberNumber)
    {
        return $query->where('member_number', $memberNumber);
    }

    // Accessors
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    // Boot method to generate UUID
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($member) {
            if (empty($member->uuid)) {
                $member->uuid = (string) Str::uuid();
            }
            if (empty($member->joined_at)) {
                $member->joined_at = now();
            }
        });
    }
}
