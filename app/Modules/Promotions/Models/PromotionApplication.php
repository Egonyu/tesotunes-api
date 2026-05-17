<?php

namespace App\Modules\Promotions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PromotionApplication extends Model
{
    use SoftDeletes;

    protected $table = 'promotion_applications';

    protected $fillable = [
        'opportunity_id',
        'promoter_profile_id',
        'applicant_user_id',
        'proposed_price_ugx',
        'proposed_price_credits',
        'pitch_message',
        'proposed_deliverables',
        'proposed_timeline_days',
        'metadata',
    ];

    // NOT in $fillable — managed by state machine:
    // status, artist_response, reviewed_at, order_id

    protected function casts(): array
    {
        return [
            'proposed_deliverables' => 'array',
            'metadata' => 'array',
            'proposed_price_ugx' => 'decimal:2',
            'proposed_price_credits' => 'integer',
            'proposed_timeline_days' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    const STATUS_SUBMITTED = 'submitted';

    const STATUS_SHORTLISTED = 'shortlisted';

    const STATUS_AWARDED = 'awarded';

    const STATUS_REJECTED = 'rejected';

    const STATUS_WITHDRAWN = 'withdrawn';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $app): void {
            if (! $app->uuid) {
                $app->uuid = Str::uuid()->toString();
            }

            if (! $app->status) {
                $app->status = self::STATUS_SUBMITTED;
            }
        });

        static::created(function (self $app): void {
            $app->opportunity?->increment('application_count');
        });

        static::deleted(function (self $app): void {
            if (in_array($app->status, [self::STATUS_SUBMITTED, self::STATUS_SHORTLISTED])) {
                $app->opportunity?->decrement('application_count');
            }
        });
    }

    // --- Relationships ---

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PromotionOpportunity::class, 'opportunity_id');
    }

    public function promoterProfile(): BelongsTo
    {
        return $this->belongsTo(PromoterProfile::class, 'promoter_profile_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_SHORTLISTED]);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('applicant_user_id', $userId);
    }

    // --- State machine ---

    public function canTransitionTo(string $status): bool
    {
        $allowed = match ($this->status) {
            self::STATUS_SUBMITTED => [self::STATUS_SHORTLISTED, self::STATUS_AWARDED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN],
            self::STATUS_SHORTLISTED => [self::STATUS_AWARDED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN],
            default => [],
        };

        return in_array($status, $allowed);
    }

    public function transitionTo(string $status, ?string $artistResponse = null): bool
    {
        if (! $this->canTransitionTo($status)) {
            return false;
        }

        $this->forceFill([
            'status' => $status,
            'reviewed_at' => now(),
            'artist_response' => $artistResponse,
        ])->save();

        return true;
    }

    public function withdraw(): bool
    {
        return $this->transitionTo(self::STATUS_WITHDRAWN);
    }

    public function reject(?string $reason = null): bool
    {
        return $this->transitionTo(self::STATUS_REJECTED, $reason);
    }
}
