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

        return [
            // stream_url: pre-signed CDN URL valid for 15 minutes (Spotify-style).
            // Only included when the user has streaming access.
            'stream_url' => $this->when(
                $canStream,
                fn () => $streamingUrl
            ),

            // audio_url kept for backward compatibility — same value as stream_url.
            'audio_url' => $this->when(
                $canStream,
                fn () => $streamingUrl
            ),

            // preview_url: 30-second preview clip, no auth required for free tracks.
            'preview_url' => $this->when(
                ! empty($this->audio_file_preview),
                fn () => StorageHelper::temporaryUrl($this->audio_file_preview, 30)
            ),
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
