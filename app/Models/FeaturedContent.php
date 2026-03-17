<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FeaturedContent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'featured_content';

    protected $fillable = [
        'uuid',
        'title',
        'subtitle',
        'image_path',
        'link',
        'type',
        'song_id',
        'album_id',
        'artist_id',
        'event_id',
        'playlist_id',
        'is_active',
        'sort_order',
        'starts_at',
        'ends_at',
        'created_by_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FeaturedContent $item) {
            if (! $item->uuid) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLive($query)
    {
        return $query
            ->where(function ($subQuery) {
                $subQuery->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($subQuery) {
                $subQuery->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }
}
