<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MusicController extends Controller
{
    /**
     * The ordered list of storage disks to check for audio files.
     * Checks configured disk first, then local fallbacks for backward compatibility.
     */
    private function getAudioDisks(): array
    {
        $defaultDisk = config('filesystems.default', 'local');
        $disks = [$defaultDisk];

        // Add local fallbacks for backward compatibility with existing files
        foreach (['music_private', 'music_public', 'public'] as $fallback) {
            if (! in_array($fallback, $disks)) {
                $disks[] = $fallback;
            }
        }

        return $disks;
    }

    /**
     * Find an audio file across configured storage disks.
     * Returns [disk_name, path] or null if not found.
     */
    private function findAudioFile(string $filePath): ?array
    {
        foreach ($this->getAudioDisks() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($filePath)) {
                    return [$diskName, $filePath];
                }
            } catch (\Exception $e) {
                \Log::debug("Disk {$diskName} check failed for {$filePath}: ".$e->getMessage());
            }
        }

        return null;
    }

    /**
     * Get streaming URL for a track
     */
    public function getStreamUrl(Request $request, $trackId)
    {
        try {
            // Validate track ID
            if (! is_numeric($trackId) || $trackId <= 0) {
                return response()->json(['error' => 'Invalid track ID'], 400);
            }

            $song = Song::with('artist')->findOrFail($trackId);

            // Check if user has access to this track
            if (! $this->userCanAccessTrack($song, $request->user())) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Resolve quality-correct file (request param clamped to subscription cap)
            $audioFile = $this->selectAudioFileByPlan($song, $request->user(), $request->query('quality'));

            if (! $audioFile) {
                \Log::warning('No audio file found for song', ['song_id' => $song->id]);

                return response()->json(['error' => 'Track file not found'], 404);
            }

            // Check configured storage disks for the file
            $found = $this->findAudioFile($audioFile);

            if (! $found) {
                \Log::error('Audio file not found on any disk', [
                    'song_id' => $song->id,
                    'db_path' => $audioFile,
                    'checked_disks' => $this->getAudioDisks(),
                ]);

                return response()->json(['error' => 'Audio file not found on server'], 404);
            }

            // Build the internal proxy URL (carries quality so streamFile() uses the same variant)
            $qualityParam = $request->query('quality');
            $proxyUrl = url('/api/v1/stream/'.$song->id).($qualityParam ? '?quality='.urlencode($qualityParam) : '');
            $streamUrl = $proxyUrl;

            // Prefer a direct pre-signed CDN URL (Spotify-style: client streams
            // straight from DO Spaces — no Laravel proxy overhead).
            // Fall back to the internal /stream/{id} proxy for local/dev storage.
            [$diskName] = $found;
            $diskDriver = config("filesystems.disks.{$diskName}.driver", 'local');
            if ($diskDriver !== 'local') {
                $cdnUrl = StorageHelper::temporaryUrl($audioFile);
                if ($cdnUrl) {
                    $streamUrl = $cdnUrl;
                }
            }

            return response()->json([
                'url' => $streamUrl,
                'track' => [
                    'id' => $song->id,
                    'title' => e($song->title),
                    'artist' => [
                        'name' => e($song->artist->name ?? 'Unknown Artist'),
                        'stage_name' => e($song->artist->stage_name ?? $song->artist->name ?? 'Unknown Artist'),
                    ],
                    'artist_name' => e($song->artist->stage_name ?? $song->artist->name ?? 'Unknown Artist'),
                    'artwork_url' => $song->artwork_url,
                    'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                    'duration_formatted' => (string) $song->duration_formatted,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Track not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error getting stream URL: '.$e->getMessage(), [
                'track_id' => $trackId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to get stream URL'], 500);
        }
    }

    /**
     * Stream audio file
     */
    public function streamFile(Request $request, $songId)
    {
        try {
            $song = Song::findOrFail($songId);

            // Check access (no signature required for now - rely on rate limiting)
            if (! $this->userCanAccessTrack($song, $request->user())) {
                abort(403, 'Access denied');
            }

            // Select audio file quality: honour ?quality= param, clamped to subscription cap
            $filePath = $this->selectAudioFileByPlan($song, $request->user(), $request->query('quality'));

            if (! $filePath) {
                abort(404, 'No audio file configured');
            }

            // Find file across configured storage disks
            $found = $this->findAudioFile($filePath);

            if (! $found) {
                \Log::error('Stream: Audio file not found', [
                    'song_id' => $songId,
                    'file_path' => $filePath,
                    'checked_disks' => $this->getAudioDisks(),
                ]);
                abort(404, 'File not found');
            }

            [$diskName, $foundPath] = $found;
            $disk = Storage::disk($diskName);

            // For local disks, stream directly from filesystem
            // For cloud disks (S3/DO Spaces), redirect to a temporary URL
            $diskDriver = config("filesystems.disks.{$diskName}.driver", 'local');

            if ($diskDriver !== 'local') {
                // Cloud storage: generate temporary signed URL and redirect
                try {
                    $temporaryUrl = $disk->temporaryUrl($foundPath, now()->addMinutes(15));

                    return redirect($temporaryUrl);
                } catch (\Exception $e) {
                    // If temporaryUrl not supported, fall back to regular URL
                    return redirect($disk->url($foundPath));
                }
            }

            // Local storage: stream the file with proper headers
            $actualPath = $disk->path($foundPath);
            $fileSize = filesize($actualPath);
            $mimeType = mime_content_type($actualPath) ?: 'audio/mpeg';

            return response()->file($actualPath, [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=31536000',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Song not found');
        } catch (\Exception $e) {
            \Log::error('Stream file error: '.$e->getMessage(), [
                'song_id' => $songId,
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Streaming error');
        }
    }

    /**
     * Get download URL for a track
     */
    public function getDownloadUrl(Request $request, $trackId)
    {
        try {
            // Validate track ID
            if (! is_numeric($trackId) || $trackId <= 0) {
                return response()->json(['error' => 'Invalid track ID'], 400);
            }

            $song = Song::with('artist')->findOrFail($trackId);

            // Check if user has access to download this track
            if (! $this->userCanDownloadTrack($song, $request->user())) {
                return response()->json(['error' => 'Download not available'], 403);
            }

            // Get audio file path (prefer highest quality)
            $filePath = $song->audio_file_original ?? $song->audio_file_320 ?? $song->audio_file_128;

            if (! $filePath) {
                return response()->json(['error' => 'Track file not found'], 404);
            }

            // Build download URL from configured storage
            $found = $this->findAudioFile($filePath);

            if (! $found) {
                return response()->json(['error' => 'Track file not found on storage'], 404);
            }

            [$diskName, $foundPath] = $found;
            $disk = Storage::disk($diskName);
            $diskDriver = config("filesystems.disks.{$diskName}.driver", 'local');

            // For cloud storage, generate temporary signed URL
            if ($diskDriver !== 'local') {
                try {
                    $downloadUrl = $disk->temporaryUrl($foundPath, now()->addMinutes(15));
                } catch (\Exception $e) {
                    $downloadUrl = $disk->url($foundPath);
                }
            } else {
                $downloadUrl = $disk->url($foundPath);
            }

            return response()->json([
                'url' => $downloadUrl,
                'filename' => preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $song->title).'.mp3',
            ]);

        } catch (\Exception $e) {
            \Log::error('Error getting download URL: '.$e->getMessage());

            return response()->json(['error' => 'Failed to get download URL'], 500);
        }
    }

    /**
     * Check if user can access track (streaming)
     * Guests can stream published/free tracks
     */
    private function userCanAccessTrack($song, $user): bool
    {
        // Allow access to published tracks (even for guests)
        if ($song->status === 'published') {
            return true;
        }

        // If not published, user must be authenticated
        if (! $user) {
            return false;
        }

        // Allow artists to access their own tracks (even unpublished)
        if ($song->artist_id === $user->artist?->id) {
            return true;
        }

        // Allow admins/moderators to access any track
        if ($user->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can download track
     */
    private function userCanDownloadTrack($song, $user): bool
    {
        if (! $user) {
            return false;
        }

        // Allow artists to download their own tracks
        if ($song->artist_id === $user->artist?->id) {
            return true;
        }

        // Allow download of free tracks (still subject to daily limit)
        if ($song->is_free) {
            return $user->canDownload();
        }

        // Paid tracks require active subscription or purchase
        if ($user->hasPurchasedSong($song)) {
            return true;
        }

        // Active subscription with remaining downloads
        return $user->hasActiveSubscription() && $user->canDownload();
    }

    /**
     * Map a quality slug from the ?quality= request param to kbps.
     * Unrecognised values default to 128 (normal).
     */
    private function qualitySlugToKbps(string $slug): int
    {
        return match ($slug) {
            'low' => 64,
            'high' => 256,
            'very_high' => 320,
            default => 128, // 'normal' and anything unknown
        };
    }

    /**
     * Select the appropriate audio file based on the effective quality.
     *
     * Priority: user-requested quality → subscription cap → nearest available file.
     * This lets premium users choose a lower quality for data-saving without
     * ever exceeding their subscription cap.
     *
     * @param  string|null  $requestedQuality  Value of the ?quality= query param (low|normal|high|very_high)
     */
    private function selectAudioFileByPlan($song, $user, ?string $requestedQuality = null): ?string
    {
        $subscriptionCap = $user ? $user->getMaxAudioQuality() : 128;

        // Clamp requested quality to what the subscription allows
        $requestedKbps = $requestedQuality ? $this->qualitySlugToKbps($requestedQuality) : $subscriptionCap;
        $effectiveKbps = min($requestedKbps, $subscriptionCap);

        if ($effectiveKbps >= 320) {
            return $song->audio_file_320 ?? $song->audio_file_original ?? $song->audio_file_128;
        }

        if ($effectiveKbps >= 256) {
            // High — serve 320 only if we're allowed to, otherwise 128
            return $song->audio_file_320 ?? $song->audio_file_128 ?? $song->audio_file_original;
        }

        // Normal (128) or Low (64): serve 128kbps; never serve 320 to honour the request
        return $song->audio_file_128 ?? $song->audio_file_original;
    }
}
