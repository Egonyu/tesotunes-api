<?php

namespace App\Modules\Podcast\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastChapter extends Model
{
    use HasFactory;

    protected $fillable = [
        'episode_id',
        'title',
        'start_time',
        'end_time',
        'description',
        'url',
        'image_url',
        'chapter_number',
    ];

    protected $casts = [
        'start_time' => 'integer',
        'end_time' => 'integer',
        'chapter_number' => 'integer',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\PodcastEpisode::class, 'episode_id');
    }

    public function getStartTimeFormattedAttribute(): string
    {
        return gmdate('H:i:s', $this->start_time);
    }

    public function getEndTimeFormattedAttribute(): ?string
    {
        return $this->end_time ? gmdate('H:i:s', $this->end_time) : null;
    }
}
