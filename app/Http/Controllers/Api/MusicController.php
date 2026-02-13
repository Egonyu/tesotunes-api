<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Http\Request;

class MusicController extends Controller
{
    /**
     * Get streaming URL for a track
     */
    public function getStreamUrl(Request $request, $trackId)
    {
        try {
            // Validate track ID
            if (!is_numeric($trackId) || $trackId <= 0) {
                return response()->json(['error' => 'Invalid track ID'], 400);
            }

            $song = Song::with('artist')->findOrFail($trackId);

            // Check if user has access to this track
            if (!$this->userCanAccessTrack($song, $request->user())) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Get the best available audio file
            $audioFile = $song->audio_file_320 ?? $song->audio_file_128 ?? $song->audio_file_original;
            
            if (!$audioFile) {
                \Log::warning('No audio file found for song', ['song_id' => $song->id]);
                return response()->json(['error' => 'Track file not found'], 404);
            }

            // Check multiple possible storage locations for the file
            // Files can be in storage/app/public, storage/app/music/music, or storage/app/music
            $possiblePaths = [
                storage_path('app/public/' . $audioFile),
                storage_path('app/music/music/' . $audioFile),
                storage_path('app/music/' . $audioFile),
            ];
            
            $actualPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $actualPath = $path;
                    break;
                }
            }
            
            if (!$actualPath) {
                \Log::error('Audio file not found on disk', [
                    'song_id' => $song->id,
                    'db_path' => $audioFile,
                    'checked_paths' => $possiblePaths
                ]);
                return response()->json(['error' => 'Audio file not found on server'], 404);
            }
            
            // Use the streaming endpoint with song ID for proper file serving
            $streamUrl = url('/api/v1/stream/' . $song->id);

            return response()->json([
                'url' => $streamUrl,
                'track' => [
                    'id' => $song->id,
                    'title' => e($song->title),
                    'artist' => [
                        'name' => e($song->artist->name ?? 'Unknown Artist'),
                        'stage_name' => e($song->artist->stage_name ?? $song->artist->name ?? 'Unknown Artist')
                    ],
                    'artist_name' => e($song->artist->stage_name ?? $song->artist->name ?? 'Unknown Artist'),
                    'artwork_url' => $song->artwork_url,
                    'duration_seconds' => (int) ($song->duration_seconds ?? 0),
                    'duration_formatted' => $song->duration_formatted ?? '0:00',
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Track not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error getting stream URL: ' . $e->getMessage(), [
                'track_id' => $trackId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            if (!$this->userCanAccessTrack($song, $request->user())) {
                abort(403, 'Access denied');
            }

            $filePath = $song->audio_file_320 ?? $song->audio_file_128 ?? $song->audio_file_original;

            if (!$filePath) {
                abort(404, 'No audio file configured');
            }

            // Check multiple possible storage locations
            $possiblePaths = [
                storage_path('app/public/' . $filePath),
                storage_path('app/music/music/' . $filePath),
                storage_path('app/music/' . $filePath),
            ];

            $actualPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $actualPath = $path;
                    break;
                }
            }

            if (!$actualPath) {
                \Log::error('Stream: Audio file not found', [
                    'song_id' => $songId,
                    'file_path' => $filePath,
                    'checked_paths' => $possiblePaths
                ]);
                abort(404, 'File not found');
            }

            // Stream the file with proper headers for audio
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
            \Log::error('Stream file error: ' . $e->getMessage(), [
                'song_id' => $songId,
                'trace' => $e->getTraceAsString()
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
            if (!is_numeric($trackId) || $trackId <= 0) {
                return response()->json(['error' => 'Invalid track ID'], 400);
            }

            $song = Song::with('artist')->findOrFail($trackId);

            // Check if user has access to download this track
            if (!$this->userCanDownloadTrack($song, $request->user())) {
                return response()->json(['error' => 'Download not available'], 403);
            }

            // Get audio file path (prefer highest quality)
            $filePath = $song->audio_file_original ?? $song->audio_file_320 ?? $song->audio_file_128;
            
            if (!$filePath) {
                return response()->json(['error' => 'Track file not found'], 404);
            }

            // Build download URL
            $downloadUrl = \App\Helpers\StorageHelper::url($filePath);

            return response()->json([
                'url' => $downloadUrl,
                'filename' => preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $song->title) . '.mp3'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error getting download URL: ' . $e->getMessage());
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
        if (!$user) {
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
        if (!$user) {
            return false;
        }

        // Allow artists to download their own tracks
        if ($song->artist_id === $user->artist?->id) {
            return true;
        }

        // Allow download of free tracks
        if ($song->is_free) {
            return true;
        }

        // Add additional download logic (purchases, subscriptions, etc.)
        return false;
    }
}