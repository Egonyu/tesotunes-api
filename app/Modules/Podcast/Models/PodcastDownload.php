<?php

namespace App\Modules\Podcast\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastDownload extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'episode_id',
        'podcast_id',
        'user_id',
        'quality',
        'file_size',
        'ip_address',
        'user_agent',
        'country',
        'city',
        'downloaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\PodcastEpisode::class, 'episode_id');
    }

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\Podcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('downloaded_at', today());
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
