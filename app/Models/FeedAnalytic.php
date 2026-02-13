<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedAnalytic extends Model
{
    protected $table = 'feed_analytics';

    protected $fillable = [
        'user_id',
        'date',
        'posts_viewed',
        'posts_liked',
        'posts_commented',
    ];

    protected $casts = [
        'date' => 'date',
        'posts_viewed' => 'integer',
        'posts_liked' => 'integer',
        'posts_commented' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
