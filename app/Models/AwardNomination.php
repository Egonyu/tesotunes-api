<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class AwardNomination extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'award_id',
        'category_id',
        'nominee_id',
        'nominee_type',
        'nominee_name',
        'nominee_artwork',
        'nominated_by_id',
        'nomination_reason',
        'status',
        'is_official',
        'approved_at',
    ];

    protected $casts = [
        'is_official' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($nomination) {
            if (empty($nomination->uuid)) {
                $nomination->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AwardCategory::class, 'category_id');
    }

    public function nominee(): MorphTo
    {
        return $this->morphTo();
    }

    public function nominatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AwardVote::class, 'nomination_id');
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOfficial($query)
    {
        return $query->where('is_official', true);
    }

    public function scopeWinners($query)
    {
        return $query->where('status', 'winner');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForAward($query, int $awardId)
    {
        return $query->where('award_id', $awardId);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    // Helper methods
    public function approve(): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }

    public function markAsWinner(): void
    {
        $this->update(['status' => 'winner']);
    }

    public function hasWon(): bool
    {
        return $this->status === 'winner';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
