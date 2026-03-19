<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSubmissionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_submission_id',
        'artist_name',
        'song_title',
        'audio_filename',
        'cover_filename',
        'phone_number',
        'email',
        'external_reference',
        'genre',
        'release_date',
        'featured_artists',
        'notes',
        'status',
        'validation_errors',
        'row_payload',
        'artist_id',
        'song_id',
    ];

    protected $casts = [
        'release_date' => 'date',
        'validation_errors' => 'array',
        'row_payload' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(CatalogSubmission::class, 'catalog_submission_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
