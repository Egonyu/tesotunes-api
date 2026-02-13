<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'slug',
        'user_id',
        'title',
        'description',
        'story',
        'category',
        'urgency',
        'beneficiary_type',
        'beneficiary_name',
        'beneficiary_relationship',
        'beneficiary_artist_id',
        'momo_network',
        'momo_number',
        'momo_name',
        'contact_name',
        'contact_phone',
        'contact_role',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'activated_at',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'revision_requested_at',
        'revision_requested_by',
        'revision_feedback',
        'closed_at',
        'closure_note',
        'is_verified',
        'verified_at',
        'verified_by',
        'verification_notes',
        'target_amount',
        'end_date',
        'view_count',
        'share_count',
        'is_featured',
        'featured_at',
        'terms_accepted',
        'terms_accepted_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_featured' => 'boolean',
        'terms_accepted' => 'boolean',
        'target_amount' => 'decimal:2',
        'view_count' => 'integer',
        'share_count' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revision_requested_at' => 'datetime',
        'closed_at' => 'datetime',
        'verified_at' => 'datetime',
        'featured_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'end_date' => 'date',
    ];

    // ── Boot ──────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $campaign) {
            if (empty($campaign->uuid)) {
                $campaign->uuid = (string) Str::uuid();
            }
            if (empty($campaign->slug)) {
                $campaign->slug = Str::slug($campaign->title) . '-' . Str::random(8);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(CampaignPledge::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CampaignUpdate::class);
    }

    public function beneficiaryArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'beneficiary_artist_id');
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('title', 'LIKE', "%{$term}%")
              ->orWhere('description', 'LIKE', "%{$term}%")
              ->orWhere('beneficiary_name', 'LIKE', "%{$term}%");
        });
    }

    // ── Helpers ───────────────────────────────────────────────

    public function getTotalRaisedAttribute(): float
    {
        return (float) $this->pledges()->sum('amount');
    }

    public function getProgressPercentAttribute(): float
    {
        if (!$this->target_amount || $this->target_amount <= 0) {
            return 0;
        }

        return min(100, round(($this->total_raised / $this->target_amount) * 100, 1));
    }
}
