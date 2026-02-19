<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Award extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'title',
        'slug',
        'description',
        'year',
        'season',
        'artwork',
        'banner',
        'nomination_starts_at',
        'nomination_ends_at',
        'voting_starts_at',
        'voting_ends_at',
        'ceremony_date',
        'status',
        'visibility',
        'allow_public_nominations',
        'allow_public_voting',
        'votes_per_category',
    ];

    protected $casts = [
        'year' => 'integer',
        'nomination_starts_at' => 'datetime',
        'nomination_ends_at' => 'datetime',
        'voting_starts_at' => 'datetime',
        'voting_ends_at' => 'datetime',
        'ceremony_date' => 'datetime',
        'allow_public_nominations' => 'boolean',
        'allow_public_voting' => 'boolean',
        'votes_per_category' => 'integer',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';

    const STATUS_NOMINATIONS_OPEN = 'nominations_open';

    const STATUS_NOMINATIONS_CLOSED = 'nominations_closed';

    const STATUS_VOTING_OPEN = 'voting_open';

    const STATUS_VOTING_CLOSED = 'voting_closed';

    const STATUS_COMPLETED = 'completed';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($award) {
            if (empty($award->uuid)) {
                $award->uuid = (string) Str::uuid();
            }
            if (empty($award->slug)) {
                $award->slug = Str::slug($award->title);
            }
        });
    }

    // Relationships
    /**
     * Categories linked to this award via nominations (pivot).
     * Categories are global; nominations connect them to a specific award.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(AwardCategory::class, 'award_nominations', 'award_id', 'category_id')
            ->distinct();
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(AwardNomination::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AwardVote::class);
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['draft', 'completed']);
    }

    public function scopeCurrentSeason($query)
    {
        return $query->where('year', date('Y'))
            ->latest('created_at');
    }

    // Helpers
    public function isNominationOpen(): bool
    {
        return $this->status === self::STATUS_NOMINATIONS_OPEN
            && now()->between($this->nomination_starts_at, $this->nomination_ends_at);
    }

    public function isVotingOpen(): bool
    {
        return $this->status === self::STATUS_VOTING_OPEN
            && now()->between($this->voting_starts_at, $this->voting_ends_at);
    }
}
