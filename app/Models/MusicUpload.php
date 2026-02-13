<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MusicUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'artist_id',
        'song_id',
        'upload_batch_id',
        'original_filename',
        'stored_filename',
        'file_path',
        'file_extension',
        'mime_type',
        'file_size_bytes',
        'file_hash',
        'duration_seconds',
        'bitrate',
        'sample_rate',
        'audio_format',
        'audio_codec',
        'is_stereo',
        'channels',
        'processing_status',
        'processing_results',
        'processing_error',
        'processing_progress',
        'detected_metadata',
        'detected_title',
        'detected_artist',
        'detected_album',
        'detected_genre',
        'detected_track_number',
        'detected_year',
        'contains_vocals',
        'vocal_percentage',
        'detected_languages',
        'explicit_content_detected',
        'audio_quality_score',
        'audio_issues',
        'upload_type',
        'upload_source',
        'upload_metadata',
        'upload_completed_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'content_flags',
        'ready_for_distribution',
        'distribution_formats',
        'master_file_path',
        'preview_file_path',
        'storage_driver',
        'storage_path_primary',
        'storage_path_backup',
        'streaming_url',
        'download_url',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'bitrate' => 'integer',
        'sample_rate' => 'integer',
        'is_stereo' => 'boolean',
        'channels' => 'integer',
        'processing_progress' => 'decimal:2',
        'processing_results' => 'array',
        'detected_metadata' => 'array',
        'detected_track_number' => 'integer',
        'contains_vocals' => 'boolean',
        'vocal_percentage' => 'decimal:2',
        'detected_languages' => 'array',
        'explicit_content_detected' => 'boolean',
        'audio_quality_score' => 'decimal:2',
        'audio_issues' => 'array',
        'upload_metadata' => 'array',
        'upload_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'content_flags' => 'array',
        'ready_for_distribution' => 'boolean',
        'distribution_formats' => 'array',
    ];

    // Relationships
    
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Helper Methods
    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    public function isProcessed(): bool
    {
        return $this->processing_status === 'processed';
    }

    public function hasFailed(): bool
    {
        return $this->processing_status === 'failed';
    }

    public function isApproved(): bool
    {
        return $this->processing_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->processing_status === 'rejected';
    }

    public function needsReview(): bool
    {
        return $this->processing_status === 'processed' && !$this->reviewed_at;
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size_bytes;

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return 'Unknown';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getBitrateDisplayAttribute(): string
    {
        return $this->bitrate ? $this->bitrate . ' kbps' : 'Unknown';
    }

    public function getSampleRateDisplayAttribute(): string
    {
        return $this->sample_rate ? number_format($this->sample_rate) . ' Hz' : 'Unknown';
    }

    public function getAudioQualityDisplayAttribute(): string
    {
        if (!$this->audio_quality_score) {
            return 'Not analyzed';
        }

        if ($this->audio_quality_score >= 90) return 'Excellent';
        if ($this->audio_quality_score >= 80) return 'Very Good';
        if ($this->audio_quality_score >= 70) return 'Good';
        if ($this->audio_quality_score >= 60) return 'Fair';
        return 'Poor';
    }

    public function getProcessingStatusBadgeAttribute(): string
    {
        return match($this->processing_status) {
            'uploaded' => '⏳ Uploaded',
            'processing' => '🔄 Processing',
            'processed' => '✅ Processed',
            'failed' => '❌ Failed',
            'approved' => '🟢 Approved',
            'rejected' => '🔴 Rejected',
            default => '⚪ Unknown'
        };
    }

    public function hasAudioIssues(): bool
    {
        return !empty($this->audio_issues);
    }

    public function getAudioIssuesDisplayAttribute(): array
    {
        if (!$this->audio_issues) {
            return [];
        }

        return array_map(function ($issue) {
            return match($issue) {
                'clipping' => 'Audio clipping detected',
                'noise' => 'Background noise detected',
                'low_volume' => 'Low volume levels',
                'high_volume' => 'Volume too high',
                'mono_in_stereo' => 'Mono audio in stereo format',
                'silence' => 'Long periods of silence',
                'distortion' => 'Audio distortion detected',
                default => ucfirst(str_replace('_', ' ', $issue))
            };
        }, $this->audio_issues);
    }

    public function isReadyForSongCreation(): bool
    {
        return $this->isProcessed() &&
               $this->ready_for_distribution &&
               !$this->hasAudioIssues();
    }

    public function getFileUrl(): string
    {
        return Storage::url($this->file_path);
    }

    public function getPreviewUrl(): ?string
    {
        return $this->preview_file_path ? Storage::url($this->preview_file_path) : null;
    }

    public function deleteFiles(): void
    {
        if ($this->file_path && Storage::exists($this->file_path)) {
            Storage::delete($this->file_path);
        }

        if ($this->master_file_path && Storage::exists($this->master_file_path)) {
            Storage::delete($this->master_file_path);
        }

        if ($this->preview_file_path && Storage::exists($this->preview_file_path)) {
            Storage::delete($this->preview_file_path);
        }
    }

    public function approve(User $reviewer, string $notes = null): void
    {
        $this->update([
            'processing_status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'ready_for_distribution' => true,
        ]);
    }

    public function reject(User $reviewer, string $reason): void
    {
        $this->update([
            'processing_status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
            'ready_for_distribution' => false,
        ]);
    }

    // Scopes
    public function scopePendingReview($query)
    {
        return $query->where('processing_status', 'processed')
                    ->whereNull('reviewed_at');
    }

    public function scopeApproved($query)
    {
        return $query->where('processing_status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('processing_status', 'rejected');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('processing_status', $status);
    }

    public function scopeByArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    public function scopeByUploadType($query, string $type)
    {
        return $query->where('upload_type', $type);
    }

    public function scopeWithAudioIssues($query)
    {
        return $query->whereNotNull('audio_issues')
                    ->where('audio_issues', '!=', '[]');
    }

    public function scopeHighQuality($query)
    {
        return $query->where('audio_quality_score', '>=', 80);
    }

    public function scopeExplicitContent($query)
    {
        return $query->where('explicit_content_detected', true);
    }

    public function scopeReadyForDistribution($query)
    {
        return $query->where('ready_for_distribution', true);
    }

    public function scopeInBatch($query, string $batchId)
    {
        return $query->where('upload_batch_id', $batchId);
    }

    // Static Methods
    public static function generateBatchId(): string
    {
        return 'batch_' . now()->format('Ymd_His') . '_' . uniqid();
    }

    public static function getSupportedFormats(): array
    {
        return [
            'mp3' => 'MP3 Audio',
            'wav' => 'WAV Audio',
            'flac' => 'FLAC Audio',
            'aac' => 'AAC Audio',
            'm4a' => 'M4A Audio',
        ];
    }

    public static function getMaxFileSize(): int
    {
        return 100 * 1024 * 1024; // 100MB
    }

    public static function getProcessingStatuses(): array
    {
        return [
            'uploaded' => 'Uploaded',
            'processing' => 'Processing',
            'processed' => 'Processed',
            'failed' => 'Failed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    }
}