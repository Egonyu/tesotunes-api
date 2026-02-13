<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdImpression;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AdService
{
    /**
     * Get ad for specific placement and page
     * 
     * @param string $placement Where to show the ad (header, sidebar, inline, footer, interstitial)
     * @param string $page Which page (home, discover, artist, etc)
     * @param string $device Device type (mobile, desktop)
     * @return Ad|null
     */
    public function getAd(string $placement, string $page, string $device = 'desktop'): ?Ad
    {
        // Premium users don't see ads
        if (Auth::check() && Auth::user()->subscription_tier === 'premium') {
            return null;
        }
        
        $cacheKey = "ad:{$placement}:{$page}:{$device}:" . (Auth::id() ?? 'guest');
        
        return Cache::remember($cacheKey, 300, function () use ($placement, $page, $device) {
            $ads = Ad::query()
                ->where('is_active', true)
                ->where('placement', $placement)
                ->where(function ($query) use ($page) {
                    $query->whereJsonContains('pages', $page)
                          ->orWhereNull('pages')
                          ->orWhereJsonLength('pages', 0);
                })
                ->where(function ($query) use ($device) {
                    if ($device === 'mobile') {
                        $query->where('desktop_only', false);
                    } else {
                        $query->where('mobile_only', false);
                    }
                })
                ->where(function ($query) {
                    $query->whereNull('start_date')
                          ->orWhere('start_date', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('end_date')
                          ->orWhere('end_date', '>=', now());
                })
                ->orderBy('priority', 'desc')
                ->get();
                
            // Return highest priority ad with some weighted randomness
            return $ads->first();
        });
    }
    
    /**
     * Record ad impression
     * 
     * @param int $adId
     * @param string $pageUrl
     * @return void
     */
    public function recordImpression(int $adId, string $pageUrl): void
    {
        try {
            $ad = Ad::find($adId);
            
            if (!$ad) {
                return;
            }
            
            // Detect device type
            $userAgent = request()->userAgent();
            $deviceType = $this->detectDeviceType($userAgent);
            
            AdImpression::create([
                'ad_id' => $adId,
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'user_agent' => $userAgent,
                'page_url' => $pageUrl,
                'device_type' => $deviceType,
            ]);
            
            // Increment counter
            $ad->increment('impressions');
            
            // Clear cache
            $this->clearAdCache($ad);
            
        } catch (\Exception $e) {
            \Log::error('Failed to record ad impression: ' . $e->getMessage());
        }
    }
    
    /**
     * Record ad click
     * 
     * @param int $adId
     * @param int|null $impressionId
     * @return void
     */
    public function recordClick(int $adId, ?int $impressionId = null): void
    {
        try {
            $ad = Ad::find($adId);
            
            if (!$ad) {
                return;
            }
            
            // Update impression if provided
            if ($impressionId) {
                $impression = AdImpression::find($impressionId);
                
                if ($impression && !$impression->clicked) {
                    $impression->update([
                        'clicked' => true,
                        'clicked_at' => now(),
                    ]);
                }
            }
            
            // Increment click counter
            $ad->increment('clicks');
            
            // Clear cache
            $this->clearAdCache($ad);
            
        } catch (\Exception $e) {
            \Log::error('Failed to record ad click: ' . $e->getMessage());
        }
    }
    
    /**
     * Calculate CTR (Click-Through Rate)
     * 
     * @param Ad $ad
     * @return float
     */
    public function calculateCTR(Ad $ad): float
    {
        if ($ad->impressions === 0) {
            return 0;
        }
        
        return ($ad->clicks / $ad->impressions) * 100;
    }
    
    /**
     * Get ad performance stats
     * 
     * @param Ad $ad
     * @param int $days
     * @return array
     */
    public function getAdStats(Ad $ad, int $days = 30): array
    {
        $impressions = AdImpression::where('ad_id', $ad->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();
        
        $totalImpressions = $impressions->count();
        $totalClicks = $impressions->where('clicked', true)->count();
        $ctr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;
        
        // Group by day
        $impressionsByDay = $impressions->groupBy(function ($impression) {
            return $impression->created_at->format('Y-m-d');
        })->map(function ($dayImpressions) {
            return [
                'impressions' => $dayImpressions->count(),
                'clicks' => $dayImpressions->where('clicked', true)->count(),
            ];
        });
        
        // Group by device
        $impressionsByDevice = $impressions->groupBy('device_type')->map->count();
        
        return [
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'ctr' => round($ctr, 2),
            'by_day' => $impressionsByDay,
            'by_device' => $impressionsByDevice,
        ];
    }
    
    /**
     * Detect device type from user agent
     * 
     * @param string $userAgent
     * @return string
     */
    public function detectDeviceType(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $userAgent)) {
            return 'tablet';
        }
        
        if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', $userAgent)) {
            return 'mobile';
        }
        
        return 'desktop';
    }
    
    /**
     * Clear ad cache
     * 
     * @param Ad $ad
     * @return void
     */
    public function clearAdCache(Ad $ad): void
    {
        $placements = ['header', 'sidebar', 'inline', 'footer', 'interstitial'];
        $pages = ['home', 'discover', 'artist', 'genres', 'playlists'];
        $devices = ['mobile', 'desktop'];
        
        foreach ($placements as $placement) {
            foreach ($pages as $page) {
                foreach ($devices as $device) {
                    Cache::forget("ad:{$placement}:{$page}:{$device}:guest");
                    Cache::forget("ad:{$placement}:{$page}:{$device}:" . Auth::id());
                }
            }
        }
    }
}
