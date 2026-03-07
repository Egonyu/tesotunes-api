<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class SaccoGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'group_number',
        'description',
        'leader_id',
        'max_members',
        'target_amount_ugx',
        'collected_amount_ugx',
        'contribution_frequency',
        'minimum_contribution_ugx',
        'status',
    ];

    protected $casts = [
        'max_members' => 'integer',
        'target_amount_ugx' => 'decimal:2',
        'collected_amount_ugx' => 'decimal:2',
        'minimum_contribution_ugx' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'active',
        'max_members' => 30,
        'collected_amount_ugx' => 0,
        'contribution_frequency' => 'monthly',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->uuid)) {
                $group->uuid = (string) Str::uuid();
            }
            if (empty($group->group_number)) {
                $group->group_number = 'GRP'.now()->format('Ymd').rand(10000, 99999);
            }
        });
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'leader_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SaccoMember::class, 'sacco_group_members', 'group_id', 'member_id')
            ->withPivot('role', 'joined_at');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getIsFullAttribute(): bool
    {
        return $this->members()->count() >= $this->max_members;
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_amount_ugx <= 0) {
            return 0;
        }

        return min(100, round(($this->collected_amount_ugx / $this->target_amount_ugx) * 100, 2));
    }
}
