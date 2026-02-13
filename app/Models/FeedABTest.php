<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedABTest extends Model
{
    protected $table = 'feed_ab_tests';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'test_name',
        'variant',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
