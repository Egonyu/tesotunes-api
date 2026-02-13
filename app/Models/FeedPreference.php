<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedPreference extends Model
{
    protected $table = 'feed_preferences';

    protected $fillable = [
        'user_id',
        'show_likes',
        'show_plays',
        'show_follows',
        'show_uploads',
    ];

    protected $casts = [
        'show_likes' => 'boolean',
        'show_plays' => 'boolean',
        'show_follows' => 'boolean',
        'show_uploads' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
