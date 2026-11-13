<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's one-tap reaction to an Edula timeline item, keyed by timeline identity. */
class EdulaReaction extends Model
{
    protected $fillable = ['user_id', 'identity', 'reaction'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
