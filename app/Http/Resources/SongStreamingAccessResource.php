<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SongStreamingAccessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canStream = $this->canStreamFor($user);
        $streamingUrl = $canStream ? $this->streamingUrlFor($user) : null;
        $previewUrl = ! empty($this->audio_file_preview)
            ? StorageHelper::temporaryUrl($this->audio_file_preview, 30)
            : null;

        return [
            // `hls_master_url` is the preferred playback source: adaptive
            // bitrate, segment-based start. Clients fall back to `stream_url`
            // (progressive) when it is null or HLS playback is unavailable.
            'hls_master_url' => $canStream && $this->hls_master_path
                ? StorageHelper::url($this->hls_master_path)
                : null,
            // `stream_url` is the canonical progressive playback field.
            // `audio_url` is an alias emitted for backward compatibility — both resolve to the same URL.
            // Clients should read `stream_url`; `audio_url` will be removed in a future API version.
            'stream_url' => $streamingUrl,
            'audio_url' => $streamingUrl,
            'preview_url' => $previewUrl,
        ];
    }

    /**
     * Check if the current user can stream this song.
     */
    protected function canStreamFor(?object $user): bool
    {
        if ($this->is_free) {
            return true;
        }

        // Current platform policy allows streaming for all users while plan
        // limits gate quality/entitlements elsewhere.
        if (! $user) {
            return true;
        }

        if (is_object($user) && method_exists($user, 'canStream')) {
            return (bool) $user->canStream();
        }

        return true;
    }

    /**
     * Resolve stream URL based on user quality entitlement.
     */
    protected function streamingUrlFor(?object $user): ?string
    {
        $maxQuality = (is_object($user) && method_exists($user, 'getMaxAudioQuality'))
            ? (int) $user->getMaxAudioQuality()
            : 128;

        return StorageHelper::streamingUrl(
            $maxQuality >= 320 ? $this->audio_file_320 : null,
            $this->audio_file_128,
            $this->audio_file_original
        );
    }
}
