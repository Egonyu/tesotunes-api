<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventWaitlistEntry extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NOTIFIED = 'notified';

    protected $fillable = [
        'uuid',
        'event_id',
        'user_id',
        'email',
        'phone',
        'status',
        'joined_at',
        'notified_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Str::uuid();
            }

            if (empty($model->joined_at)) {
                $model->joined_at = now();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
