<?php

namespace App\Models\Concerns;

use App\Helpers\StorageHelper;

/**
 * Resolves all audio and artwork URLs for a Song via StorageHelper.
 *
 * Why a trait: URL generation touches 60+ call sites across resources,
 * services, and observers, but each method is 1-3 lines.  Keeping them
 * together in their own file makes the StorageHelper contract clear and
 * keeps Song.php free of URL-plumbing details.
 */
trait HasAudioUrls
{
    /**
     * Pre-signed CDN streaming URL.  Picks the best available quality.
     */
    public function getAudioUrlAttribute(): string
    {
        return StorageHelper::streamingUrl(
            $this->audio_file_320,
            $this->audio_file_128,
            $this->audio_file_original
        ) ?? '';
    }

    /**
     * Backward-compat: $song->audio_file maps to audio_file_original.
     */
    public function getAudioFileAttribute(): ?string
    {
        return $this->audio_file_original;
    }

    /**
     * Backward-compat alias for audio_file_original.
     */
    public function getFilePathAttribute(): ?string
    {
        return $this->audio_file_original;
    }

    public function getArtworkUrlAttribute(): ?string
    {
        return StorageHelper::artworkUrl($this->artwork);
    }

    /**
     * 128 kbps pre-signed URL — data-efficient for African mobile users.
     */
    public function getCompressedAudioUrlAttribute(): string
    {
        return StorageHelper::temporaryUrl($this->audio_file_128) ?? $this->audio_url;
    }

    /**
     * 30-second preview clip — slightly longer expiry for previewing.
     */
    public function getPreviewAudioUrlAttribute(): string
    {
        return StorageHelper::temporaryUrl($this->audio_file_preview, 30) ?? $this->audio_url;
    }

    /**
     * Signed download URL for the original audio file.
     */
    public function getDownloadUrlAttribute(): string
    {
        if ($this->audio_file_original) {
            return StorageHelper::temporaryUrl($this->audio_file_original, 15) ?? '#';
        }

        return '#';
    }
}
