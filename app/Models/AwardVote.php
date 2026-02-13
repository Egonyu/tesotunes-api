<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AwardVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'award_id',
        'category_id',
        'nomination_id',
        'user_id',
        'weight',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($vote) {
            if (empty($vote->uuid)) {
                $vote->uuid = (string) Str::uuid();
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

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(AwardNomination::class, 'nomination_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeForAward($query, int $awardId)
    {
        return $query->where('award_id', $awardId);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeForNomination($query, int $nominationId)
    {
        return $query->where('nomination_id', $nominationId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
