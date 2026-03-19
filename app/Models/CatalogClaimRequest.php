<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogClaimRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'claimant_user_id',
        'artist_id',
        'requested_song_ids',
        'phone_number',
        'message',
        'evidence',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_song_ids' => 'array',
        'evidence' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
