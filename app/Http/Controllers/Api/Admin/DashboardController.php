<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function stats()
    {
        try {
            $today = Carbon::today();
            $thisWeek = Carbon::now()->startOfWeek();
            $thisMonth = Carbon::now()->startOfMonth();
            $lastMonth = Carbon::now()->subMonth();

            // Users stats
            $totalUsers = DB::table('users')->count();
            $newToday = DB::table('users')->whereDate('created_at', $today)->count();
            $newThisWeek = DB::table('users')->where('created_at', '>=', $thisWeek)->count();
            $activeUsers = Schema::hasColumn('users', 'is_active')
                ? DB::table('users')->where('is_active', 1)->count()
                : $totalUsers;
            $premiumUsers = Schema::hasTable('subscriptions')
                ? DB::table('subscriptions')->where('status', 'active')->distinct('user_id')->count()
                : 0;

            $lastWeekUsers = DB::table('users')
                ->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
                ->where('created_at', '<', $thisWeek)
                ->count();
            $usersChange = $lastWeekUsers > 0
                ? (($newThisWeek - $lastWeekUsers) / $lastWeekUsers) * 100
                : 0;

            // Songs stats
            $totalSongs = Schema::hasTable('songs') ? DB::table('songs')->count() : 0;
            $publishedSongs = Schema::hasTable('songs') ? DB::table('songs')->where('status', 'published')->count() : 0;
            $pendingReview = Schema::hasTable('songs') ? DB::table('songs')->where('status', 'pending_review')->count() : 0;
            $draftSongs = Schema::hasTable('songs') ? DB::table('songs')->where('status', 'draft')->count() : 0;

            $totalPlays = Schema::hasTable('plays') ? DB::table('plays')->count() : 0;
            $playsToday = Schema::hasTable('plays') ? DB::table('plays')->whereDate('created_at', $today)->count() : 0;

            $lastWeekSongs = Schema::hasTable('songs')
                ? DB::table('songs')
                    ->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
                    ->where('created_at', '<', $thisWeek)
                    ->count()
                : 0;
            $songsChange = $lastWeekSongs > 0
                ? ((DB::table('songs')->where('created_at', '>=', $thisWeek)->count() - $lastWeekSongs) / $lastWeekSongs) * 100
                : 0;

            // Albums stats
            $totalAlbums = Schema::hasTable('albums') ? DB::table('albums')->count() : 0;
            $releasedAlbums = Schema::hasTable('albums') ? DB::table('albums')->where('status', 'released')->count() : 0;
            $upcomingAlbums = Schema::hasTable('albums')
                ? DB::table('albums')->where('release_date', '>', now())->count()
                : 0;

            // Artists stats
            $totalArtists = Schema::hasTable('artists') ? DB::table('artists')->count() : 0;
            $verifiedArtists = Schema::hasTable('artists') ? DB::table('artists')->where('is_verified', 1)->count() : 0;
            $pendingVerification = Schema::hasTable('artist_applications')
                ? DB::table('artist_applications')->where('status', 'pending')->count()
                : 0;

            // Revenue stats
            $totalRevenue = Schema::hasTable('payments')
                ? (DB::table('payments')->where('status', 'completed')->sum('amount') ?? 0)
                : 0;
            $thisMonthRevenue = Schema::hasTable('payments')
                ? (DB::table('payments')->where('status', 'completed')->where('created_at', '>=', $thisMonth)->sum('amount') ?? 0)
                : 0;
            $lastMonthRevenue = Schema::hasTable('payments')
                ? (DB::table('payments')->where('status', 'completed')->where('created_at', '>=', $lastMonth->startOfMonth())->where('created_at', '<', $thisMonth)->sum('amount') ?? 0)
                : 0;

            $revenueChange = $lastMonthRevenue > 0
                ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                : 0;

            // Activity stats
            $playsThisWeek = Schema::hasTable('plays') ? DB::table('plays')->where('created_at', '>=', $thisWeek)->count() : 0;
            $totalDownloads = Schema::hasTable('downloads') ? DB::table('downloads')->count() : 0;
            $downloadsToday = Schema::hasTable('downloads') ? DB::table('downloads')->whereDate('created_at', $today)->count() : 0;
            $downloadsThisWeek = Schema::hasTable('downloads') ? DB::table('downloads')->where('created_at', '>=', $thisWeek)->count() : 0;

            return response()->json([
                'data' => [
                    'users' => [
                        'total' => $totalUsers,
                        'new_today' => $newToday,
                        'new_this_week' => $newThisWeek,
                        'change_percentage' => round((float) $usersChange, 1),
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
                        'change_percentage' => round((float) $songsChange, 1),
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
                        'change_percentage' => round((float) $revenueChange, 1),
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
        } catch (\Throwable $e) {
            \Log::error('DashboardController@stats failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to load dashboard stats.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get recent activity
     */
    public function recentActivity()
    {
        try {
            // Recent songs
            $recentSongs = collect();
            if (Schema::hasTable('songs') && Schema::hasTable('artists')) {
                $artistNameCol = Schema::hasColumn('artists', 'stage_name') ? 'artists.stage_name' : 'artists.name';
                $recentSongs = DB::table('songs')
                    ->join('artists', 'songs.artist_id', '=', 'artists.id')
                    ->select('songs.id', 'songs.title', DB::raw("{$artistNameCol} as artist_name"), 'songs.created_at')
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
            }

            // Recent albums
            $recentAlbums = collect();
            if (Schema::hasTable('albums') && Schema::hasTable('artists')) {
                $artistNameCol = Schema::hasColumn('artists', 'stage_name') ? 'artists.stage_name' : 'artists.name';
                $recentAlbums = DB::table('albums')
                    ->join('artists', 'albums.artist_id', '=', 'artists.id')
                    ->select('albums.id', 'albums.title', DB::raw("{$artistNameCol} as artist_name"), 'albums.created_at')
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
            }

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
        } catch (\Throwable $e) {
            \Log::error('DashboardController@recentActivity failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to load recent activity.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
