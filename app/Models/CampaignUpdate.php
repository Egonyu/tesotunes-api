<?php

namespace App\Models;

use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CampaignUpdate extends Model
{
    use HasComments, SoftDeletes;

    protected $table = 'campaign_updates';

    protected $fillable = [
        'uuid',
        'campaign_id',
        'user_id',
        'title',
        'content',
        'type',
        'is_pinned',
        'is_public',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_public' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $update) {
            if (empty($update->uuid)) {
                $update->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
