<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventPromotionRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'event_id',
        'requested_by_user_id',
        'moderated_by_user_id',
        'promotion_slug',
        'promotion_title',
        'promotion_type',
        'promotion_platform',
        'price_credits',
        'price_ugx',
        'status',
        'request_notes',
        'moderation_notes',
        'featured_image_url',
        'payload',
        'requested_at',
        'moderated_at',
    ];

    protected $casts = [
        'price_credits' => 'decimal:2',
        'price_ugx' => 'decimal:2',
        'payload' => 'array',
        'requested_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (blank($request->uuid)) {
                $request->uuid = (string) \Str::uuid();
            }

            if (blank($request->requested_at)) {
                $request->requested_at = now();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    public function approve(User $user, ?string $notes = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'moderated_by_user_id' => $user->id,
            'moderation_notes' => $notes,
            'moderated_at' => now(),
        ])->save();
    }

    public function reject(User $user, ?string $notes = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'moderated_by_user_id' => $user->id,
            'moderation_notes' => $notes,
            'moderated_at' => now(),
        ])->save();
    }
}
