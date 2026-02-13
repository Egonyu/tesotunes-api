<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoBoardMember extends Model
{
    protected $fillable = [
        'member_id',
        'position',
        'appointed_at',
        'term_ends_at',
        'status',
        'responsibilities',
    ];

    protected $casts = [
        'appointed_at' => 'datetime',
        'term_ends_at' => 'datetime',
        'responsibilities' => 'json',
    ];

    /**
     * Get the member
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
