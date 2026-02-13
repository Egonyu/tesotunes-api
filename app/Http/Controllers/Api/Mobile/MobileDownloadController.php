<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Models\Song;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MobileDownloadController extends Controller
{
    /**
     * Check download limits for current user
     * Free users: 10 downloads/day
     * Premium users: Unlimited
     */
    public function checkDownloadLimit(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $isPremium = $user->subscription_tier === 'premium';
        
        if ($isPremium) {
            return response()->json([
                'can_download' => true,
                'is_premium' => true,
                'downloads_today' => 0,
                'limit' => null,
                'remaining' => null,
            ]);
        }
        
        // Free user - check daily limit
        $downloadsToday = Download::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();
        
        $limit = 10;
        $remaining = max(0, $limit - $downloadsToday);
        
        return response()->json([
            'can_download' => $remaining > 0,
            'is_premium' => false,
            'downloads_today' => $downloadsToday,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_at' => now()->endOfDay()->toISOString(),
        ]);
    }
    
    /**
     * Get signed download URL for a song
     * Enforces freemium limits
     */
    public function getDownloadUrl(Request $request, Song $song): JsonResponse
    {
        $user = $request->user();
        
        // Check if song is downloadable
        if (!$song->is_downloadable) {
            return response()->json([
                'error' => 'This song is not available for download'
            ], 403);
        }
        
        // Check download limit
        $limitCheck = $this->checkDownloadLimit($request);
        $limitData = $limitCheck->getData(true);
        
        if (!$limitData['can_download']) {
            return response()->json([
                'error' => 'Daily download limit reached',
                'upgrade_required' => true,
                'limit_info' => $limitData,
            ], 429);
        }
        
        // Determine quality based on subscription
        $isPremium = $limitData['is_premium'];
        $audioFile = $isPremium ? $song->audio_file_320 : $song->audio_file_128;
        $quality = $isPremium ? '320kbps' : '128kbps';
        
        if (!$audioFile || !Storage::disk('digitalocean')->exists($audioFile)) {
            return response()->json([
                'error' => 'Audio file not available'
            ], 404);
        }
        
        // Generate signed URL (15 minutes expiry)
        try {
            $signedUrl = $this->generateSignedUrl($audioFile);
            
            // Record download
            Download::create([
                'user_id' => $user->id,
                'song_id' => $song->id,
                'quality' => $quality,
                'file_size_bytes' => Storage::disk('digitalocean')->size($audioFile),
                'downloaded_at' => now(),
                'ip_address' => $request->ip(),
                'device_type' => 'mobile',
            ]);
            
            // Increment song download count
            $song->increment('download_count');
            
            return response()->json([
                'success' => true,
                'download_url' => $signedUrl,
                'quality' => $quality,
                'file_size' => Storage::disk('digitalocean')->size($audioFile),
                'expires_at' => now()->addMinutes(15)->toISOString(),
                'song' => [
                    'id' => $song->id,
                    'title' => $song->title,
                    'artist' => $song->artist->stage_name ?? 'Unknown Artist',
                    'artwork' => $song->artwork ? Storage::disk('digitalocean')->url($song->artwork) : null,
                    'duration' => $song->duration_seconds,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate download URL',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get download URLs for multiple songs (for offline sync)
     */
    public function getBatchDownloadUrls(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_ids' => 'required|array|max:50',
            'song_ids.*' => 'required|integer|exists:songs,id',
        ]);
        
        $user = $request->user();
        
        // Check download limit
        $limitCheck = $this->checkDownloadLimit($request);
        $limitData = $limitCheck->getData(true);
        
        if (!$limitData['can_download']) {
            return response()->json([
                'error' => 'Daily download limit reached',
                'upgrade_required' => true,
                'limit_info' => $limitData,
            ], 429);
        }
        
        $songs = Song::whereIn('id', $validated['song_ids'])
            ->where('is_downloadable', true)
            ->with('artist:id,stage_name')
            ->get();
        
        $isPremium = $limitData['is_premium'];
        $downloads = [];
        $errors = [];
        
        foreach ($songs as $song) {
            // Check if we've exceeded limit for free users
            if (!$isPremium && count($downloads) >= $limitData['remaining']) {
                break;
            }
            
            try {
                $audioFile = $isPremium ? $song->audio_file_320 : $song->audio_file_128;
                $quality = $isPremium ? '320kbps' : '128kbps';
                
                if (!$audioFile || !Storage::disk('digitalocean')->exists($audioFile)) {
                    $errors[] = [
                        'song_id' => $song->id,
                        'error' => 'Audio file not available',
                    ];
                    continue;
                }
                
                $signedUrl = $this->generateSignedUrl($audioFile);
                
                // Record download
                Download::create([
                    'user_id' => $user->id,
                    'song_id' => $song->id,
                    'quality' => $quality,
                    'file_size_bytes' => Storage::disk('digitalocean')->size($audioFile),
                    'downloaded_at' => now(),
                    'ip_address' => $request->ip(),
                    'device_type' => 'mobile',
                ]);
                
                $song->increment('download_count');
                
                $downloads[] = [
                    'song_id' => $song->id,
                    'download_url' => $signedUrl,
                    'quality' => $quality,
                    'file_size' => Storage::disk('digitalocean')->size($audioFile),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                    'song' => [
                        'id' => $song->id,
                        'title' => $song->title,
                        'artist' => $song->artist->stage_name ?? 'Unknown Artist',
                        'artwork' => $song->artwork ? Storage::disk('digitalocean')->url($song->artwork) : null,
                        'duration' => $song->duration_seconds,
                    ],
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'song_id' => $song->id,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'downloads' => $downloads,
            'total_requested' => count($validated['song_ids']),
            'total_succeeded' => count($downloads),
            'total_failed' => count($errors),
            'errors' => $errors,
            'limit_info' => $limitData,
        ]);
    }
    
    /**
     * Get user's download history
     */
    public function getDownloadHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $downloads = Download::where('user_id', $user->id)
            ->with(['song.artist:id,stage_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        
        return response()->json([
            'success' => true,
            'downloads' => $downloads->map(function ($download) {
                return [
                    'id' => $download->id,
                    'song_id' => $download->song_id,
                    'song_title' => $download->song->title ?? 'Unknown',
                    'artist_name' => $download->song->artist->stage_name ?? 'Unknown Artist',
                    'downloaded_at' => $download->created_at->toISOString(),
                    'file_size' => $download->file_size,
                    'download_type' => $download->download_type,
                ];
            }),
            'pagination' => [
                'current_page' => $downloads->currentPage(),
                'total_pages' => $downloads->lastPage(),
                'total' => $downloads->total(),
                'per_page' => $downloads->perPage(),
            ],
        ]);
    }
    
    /**
     * Download playlist for offline access
     */
    public function downloadPlaylist(Request $request, Playlist $playlist): JsonResponse
    {
        $user = $request->user();
        
        // Check if user owns or can access playlist
        if ($playlist->user_id !== $user->id && $playlist->visibility !== 'public') {
            return response()->json([
                'error' => 'Playlist not accessible'
            ], 403);
        }
        
        $songs = $playlist->songs()
            ->where('is_downloadable', true)
            ->with('artist:id,stage_name')
            ->get();
        
        if ($songs->isEmpty()) {
            return response()->json([
                'error' => 'No downloadable songs in playlist'
            ], 404);
        }
        
        // Use batch download for playlist
        $request->merge(['song_ids' => $songs->pluck('id')->toArray()]);
        
        return $this->getBatchDownloadUrls($request);
    }
    
    /**
     * Generate signed URL for DigitalOcean Spaces
     */
    private function generateSignedUrl(string $filePath): string
    {
        // For testing environments, return a fake URL
        if (app()->environment('testing')) {
            return 'https://fake-download-url.com/' . $filePath;
        }
        
        $adapter = Storage::disk('digitalocean')->getAdapter();
        
        // Check if adapter supports S3 client (not available in local/testing adapters)
        if (method_exists($adapter, 'getClient')) {
            $client = $adapter->getClient();
            
            $command = $client->getCommand('GetObject', [
                'Bucket' => config('filesystems.disks.digitalocean.bucket'),
                'Key' => $filePath,
            ]);
            
            $request = $client->createPresignedRequest($command, '+15 minutes');
            
            return (string) $request->getUri();
        }
        
        // For local environments, return a simple URL
        return Storage::disk('digitalocean')->url($filePath);
    }
}
