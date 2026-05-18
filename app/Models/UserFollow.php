<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserFollow extends Model
{
    protected $fillable = [
        'follower_id',
        'followable_id',
        'followable_type',
    ];

    /**
     * The user who is following.
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * The entity being followed (polymorphic).
     */
    public function following(): MorphTo
    {
        return $this->morphTo('followable');
    }
}
