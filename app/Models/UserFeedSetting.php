<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedSetting extends Model
{
    protected $table = 'user_feed_settings';

    protected $fillable = [
        'user_id',
        'preferences',
    ];

    protected $casts = [
        'preferences' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
