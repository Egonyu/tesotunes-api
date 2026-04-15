<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LegalPage extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_TERMS_OF_SERVICE = 'terms';

    const TYPE_PRIVACY_POLICY = 'privacy';

    const TYPE_ACCEPTABLE_USE = 'acceptable_use';

    const TYPE_ARTIST_AGREEMENT = 'artist_agreement';

    const TYPE_COPYRIGHT = 'copyright';

    const TYPE_COOKIES = 'cookies';

    const TYPE_DISCLAIMER = 'disclaimer';

    const TYPE_PAYMENT_TERMS = 'payment_terms';

    const TYPE_DMCA = 'dmca';

    const TYPE_ACCESSIBILITY = 'accessibility';

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const STATUS_ARCHIVED = 'archived';

    const APPLIES_TO_ALL = 'all';

    const APPLIES_TO_USERS = 'users';

    const APPLIES_TO_ARTISTS = 'artists';

    const APPLIES_TO_LABELS = 'labels';

    const APPLIES_TO_EVENT_ORGANIZERS = 'event_organizers';

    protected $fillable = [
        'uuid',
        'slug',
        'title',
        'subtitle',
        'type',
        'description',
        'content',
        'status',
        'version',
        'applies_to',
        'metadata',
        'requires_acceptance',
        'effective_date',
        'sunset_date',
        'created_by',
        'updated_by',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'metadata' => 'json',
        'requires_acceptance' => 'boolean',
        'effective_date' => 'datetime',
        'sunset_date' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegalPage $page) {
            if (empty($page->uuid)) {
                $page->uuid = Str::uuid();
            }
            if (empty($page->slug) && isset($page->attributes['title'])) {
                $page->slug = Str::slug($page->title);
            }
        });
    }

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalPageAcceptance::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalPageVersion::class);
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('effective_date')
                    ->orWhere('effective_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('sunset_date')
                    ->orWhere('sunset_date', '>', now());
            });
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeAppliesTo($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->where('applies_to', self::APPLIES_TO_ALL)
                ->orWhere('applies_to', $role);
        });
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    // Methods
    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return false;
        }

        if ($this->effective_date && $this->effective_date > now()) {
            return false;
        }

        if ($this->sunset_date && $this->sunset_date <= now()) {
            return false;
        }

        return true;
    }

    public function userAccepted(User $user): bool
    {
        if (! $this->requires_acceptance) {
            return true;
        }

        return $this->acceptances()
            ->where('user_id', $user->id)
            ->where('version', $this->version)
            ->exists();
    }

    public function recordAcceptance(User $user, ?string $ipAddress = null, ?string $userAgent = null): LegalPageAcceptance
    {
        return $this->acceptances()->create([
            'user_id' => $user->id,
            'version' => $this->version,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'accepted_at' => now(),
        ]);
    }

    public function countAcceptances(): int
    {
        return $this->acceptances()
            ->where('version', $this->version)
            ->count();
    }

    public function publish(User $user): void
    {
        $this->update([
            'status' => self::STATUS_PUBLISHED,
            'published_by' => $user->id,
            'published_at' => now(),
            'effective_date' => $this->effective_date ?? now(),
        ]);
    }

    public function createNewVersion(User $user, ?string $changelog = null): LegalPageVersion
    {
        $this->increment('version');

        $newVersion = LegalPageVersion::create([
            'legal_page_id' => $this->id,
            'version_number' => $this->version,
            'title' => $this->title,
            'content' => $this->content,
            'changelog' => $changelog,
            'created_by' => $user->id,
        ]);

        return $newVersion;
    }

    public function getTypeDisplayAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            self::TYPE_PRIVACY_POLICY => 'Privacy Policy',
            self::TYPE_ACCEPTABLE_USE => 'Acceptable Use Policy',
            self::TYPE_ARTIST_AGREEMENT => 'Artist Agreement',
            self::TYPE_COPYRIGHT => 'Copyright Policy',
            self::TYPE_COOKIES => 'Cookie Policy',
            self::TYPE_DISCLAIMER => 'Disclaimer',
            self::TYPE_PAYMENT_TERMS => 'Payment Terms',
            self::TYPE_DMCA => 'DMCA Policy',
            self::TYPE_ACCESSIBILITY => 'Accessibility Statement',
            default => ucfirst($this->type),
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'bg-yellow-100 text-yellow-800',
            self::STATUS_PUBLISHED => 'bg-green-100 text-green-800',
            self::STATUS_ARCHIVED => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
