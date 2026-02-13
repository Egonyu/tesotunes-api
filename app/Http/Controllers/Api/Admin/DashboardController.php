<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function stats()
    {
        $today = Carbon::today();
        $thisWeek = Carbon::now()->startOfWeek();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth();

        // Users stats
        $totalUsers = DB::table('users')->count();
        $newToday = DB::table('users')->whereDate('created_at', $today)->count();
        $newThisWeek = DB::table('users')->where('created_at', '>=', $thisWeek)->count();
        $activeUsers = DB::table('users')->where('is_active', 1)->count();
        $premiumUsers = DB::table('subscriptions')
            ->where('status', 'active')
            ->distinct('user_id')
            ->count();

        $lastWeekUsers = DB::table('users')
            ->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
            ->where('created_at', '<', $thisWeek)
            ->count();
        $usersChange = $lastWeekUsers > 0 
            ? (($newThisWeek - $lastWeekUsers) / $lastWeekUsers) * 100 
            : 0;

        // Songs stats
        $totalSongs = DB::table('songs')->count();
        $publishedSongs = DB::table('songs')->where('status', 'published')->count();
        $pendingReview = DB::table('songs')->where('status', 'pending_review')->count();
        $draftSongs = DB::table('songs')->where('status', 'draft')->count();
        
        $totalPlays = DB::table('plays')->count();
        $playsToday = DB::table('plays')->whereDate('created_at', $today)->count();
        
        $lastWeekSongs = DB::table('songs')
            ->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
            ->where('created_at', '<', $thisWeek)
            ->count();
        $songsChange = $lastWeekSongs > 0 
            ? ((DB::table('songs')->where('created_at', '>=', $thisWeek)->count() - $lastWeekSongs) / $lastWeekSongs) * 100 
            : 0;

        // Albums stats
        $totalAlbums = DB::table('albums')->count();
        $releasedAlbums = DB::table('albums')->where('status', 'released')->count();
        $upcomingAlbums = DB::table('albums')
            ->where('release_date', '>', now())
            ->count();

        // Artists stats
        $totalArtists = DB::table('artists')->count();
        $verifiedArtists = DB::table('artists')->where('is_verified', 1)->count();
        $pendingVerification = DB::table('artist_applications')
            ->where('status', 'pending')
            ->count();

        // Revenue stats
        $totalRevenue = DB::table('payments')
            ->where('status', 'completed')
            ->sum('amount') ?? 0;
        $thisMonthRevenue = DB::table('payments')
            ->where('status', 'completed')
            ->where('created_at', '>=', $thisMonth)
            ->sum('amount') ?? 0;
        $lastMonthRevenue = DB::table('payments')
            ->where('status', 'completed')
            ->where('created_at', '>=', $lastMonth->startOfMonth())
            ->where('created_at', '<', $thisMonth)
            ->sum('amount') ?? 0;
        
        $revenueChange = $lastMonthRevenue > 0 
            ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 
            : 0;

        // Activity stats
        $playsThisWeek = DB::table('plays')->where('created_at', '>=', $thisWeek)->count();
        $totalDownloads = DB::table('downloads')->count();
        $downloadsToday = DB::table('downloads')->whereDate('created_at', $today)->count();
        $downloadsThisWeek = DB::table('downloads')->where('created_at', '>=', $thisWeek)->count();

        return response()->json([
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'new_today' => $newToday,
                    'new_this_week' => $newThisWeek,
                    'change_percentage' => round($usersChange, 1),
                    'active_users' => $activeUsers,
                    'premium_users' => $premiumUsers,
                ],
                'songs' => [
                    'total' => $totalSongs,
                    'published' => $publishedSongs,
                    'pending_review' => $pendingReview,
                    'draft' => $draftSongs,
                    'total_plays' => $totalPlays,
                    'plays_today' => $playsToday,
                    'change_percentage' => round($songsChange, 1),
                ],
                'albums' => [
                    'total' => $totalAlbums,
                    'released' => $releasedAlbums,
                    'upcoming' => $upcomingAlbums,
                ],
                'artists' => [
                    'total' => $totalArtists,
                    'verified' => $verifiedArtists,
                    'pending_verification' => $pendingVerification,
                ],
                'revenue' => [
                    'total' => $totalRevenue,
                    'this_month' => $thisMonthRevenue,
                    'last_month' => $lastMonthRevenue,
                    'change_percentage' => round($revenueChange, 1),
                    'currency' => 'UGX',
                ],
                'activity' => [
                    'total_plays' => $totalPlays,
                    'plays_today' => $playsToday,
                    'plays_this_week' => $playsThisWeek,
                    'total_downloads' => $totalDownloads,
                    'downloads_today' => $downloadsToday,
                    'downloads_this_week' => $downloadsThisWeek,
                ],
            ],
        ]);
    }

    /**
     * Get recent activity
     */
    public function recentActivity()
    {
        // Recent songs
        $recentSongs = DB::table('songs')
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->select('songs.id', 'songs.title', 'artists.name as artist_name', 'songs.created_at')
            ->orderBy('songs.created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($song) {
                return [
                    'id' => $song->id,
                    'title' => $song->title,
                    'artist' => ['name' => $song->artist_name],
                    'created_at' => $song->created_at,
                ];
            });

        // Recent albums
        $recentAlbums = DB::table('albums')
            ->join('artists', 'albums.artist_id', '=', 'artists.id')
            ->select('albums.id', 'albums.title', 'artists.name as artist_name', 'albums.created_at')
            ->orderBy('albums.created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($album) {
                return [
                    'id' => $album->id,
                    'title' => $album->title,
                    'artist' => ['name' => $album->artist_name],
                    'created_at' => $album->created_at,
                ];
            });

        // Recent users
        $recentUsers = DB::table('users')
            ->select('id', 'username as name', 'email', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'songs' => $recentSongs,
                'albums' => $recentAlbums,
                'users' => $recentUsers,
            ],
        ]);
    }
}
