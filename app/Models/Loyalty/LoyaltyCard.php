<?php

namespace App\Models\Loyalty;

use App\Models\Artist;
use App\Models\Event;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LoyaltyCard extends Model
{
    use HasComments, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'artist_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'banner_url',
        'primary_color',
        'secondary_color',
        'tiers',
        'status',
        'published_at',
        'total_members',
        'monthly_revenue',
        'allow_monthly',
        'allow_yearly',
        'auto_renew',
    ];

    protected $casts = [
        'tiers' => 'array',
        'published_at' => 'datetime',
        'allow_monthly' => 'boolean',
        'allow_yearly' => 'boolean',
        'auto_renew' => 'boolean',
        'total_members' => 'integer',
        'monthly_revenue' => 'decimal:2',
    ];

    // ── Boot ──────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $card) {
            $card->uuid = $card->uuid ?: (string) Str::uuid();
            $card->slug = $card->slug ?: Str::slug($card->name);
        });
    }

    // ── Relationships ─────────────────────────────────────────────

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(LoyaltyCardMember::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function tierConfig(string $tier): ?array
    {
        $tiers = $this->tiers ?? [];

        // Associative lookup (keyed by tier name)
        if (isset($tiers[$tier])) {
            return $tiers[$tier];
        }

        // Numeric array lookup (list of objects with 'name' field)
        foreach ($tiers as $config) {
            if (is_array($config) && ($config['name'] ?? null) === $tier) {
                return $config;
            }
        }

        return null;
    }

    public function availableTiers(): array
    {
        $tiers = $this->tiers ?? [];

        if (empty($tiers)) {
            return [];
        }

        // Check if numeric array (first element has 'name' key)
        $first = reset($tiers);
        if (is_array($first) && isset($first['name'])) {
            return array_column($tiers, 'name');
        }

        // Associative format
        return array_keys($tiers);
    }

    public function tierPrice(string $tier, string $subscriptionType = 'monthly'): ?float
    {
        $config = $this->tierConfig($tier);
        if (! $config) {
            return null;
        }

        $key = $subscriptionType === 'yearly' ? 'price_yearly' : 'price_monthly';

        return $config[$key] ?? null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
