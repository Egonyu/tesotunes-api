<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Download;
use App\Models\Payment;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\HandlesApiErrors;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get dashboard statistics
     */
    public function stats()
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:dashboard:stats', now()->addMinutes(5), function () {
                $today = Carbon::today();
                $thisWeek = Carbon::now()->startOfWeek();
                $thisMonth = Carbon::now()->startOfMonth();
                $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
                $playHistoryTimestampColumn = $this->resolvePlayHistoryTimestampColumn();
                $downloadTimestampColumn = $this->resolveDownloadTimestampColumn();

                // Users stats
                $totalUsers = User::count();
                $newToday = User::whereDate('created_at', $today)->count();
                $newThisWeek = User::where('created_at', '>=', $thisWeek)->count();
                $activeUsers = User::where('is_active', true)->count();
                $premiumUsers = UserSubscription::where('status', 'active')->distinct('user_id')->count('user_id');

                $lastWeekUsers = User::where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
                    ->where('created_at', '<', $thisWeek)
                    ->count();
                $usersChange = $lastWeekUsers > 0
                    ? (($newThisWeek - $lastWeekUsers) / $lastWeekUsers) * 100
                    : 0;

                // Songs stats
                $totalSongs = Song::count();
                $publishedSongs = Song::where('status', 'published')->count();
                $pendingReview = Song::where('status', 'pending_review')->count();
                $draftSongs = Song::where('status', 'draft')->count();

                $totalPlays = PlayHistory::count();
                $playsToday = PlayHistory::whereDate($playHistoryTimestampColumn, $today)->count();

                $newSongsThisWeek = Song::where('created_at', '>=', $thisWeek)->count();
                $lastWeekSongs = Song::where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek())
                    ->where('created_at', '<', $thisWeek)
                    ->count();
                $songsChange = $lastWeekSongs > 0
                    ? (($newSongsThisWeek - $lastWeekSongs) / $lastWeekSongs) * 100
                    : 0;

                // Albums stats
                $totalAlbums = Album::count();
                $releasedAlbums = Album::where('status', 'released')->count();
                $upcomingAlbums = Album::where('release_date', '>', now())->count();

                // Artists stats
                $totalArtists = Artist::count();
                $verifiedArtists = Artist::where('is_verified', true)->count();
                $pendingVerification = 0;

                // Revenue stats
                $totalRevenue = Payment::where('status', 'completed')->sum('amount') ?? 0;
                $thisMonthRevenue = Payment::where('status', 'completed')
                    ->where('created_at', '>=', $thisMonth)
                    ->sum('amount') ?? 0;
                $lastMonthRevenue = Payment::where('status', 'completed')
                    ->where('created_at', '>=', $lastMonthStart)
                    ->where('created_at', '<', $thisMonth)
                    ->sum('amount') ?? 0;
                $revenueChange = $lastMonthRevenue > 0
                    ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                    : 0;

                // Activity stats
                $playsThisWeek = PlayHistory::where($playHistoryTimestampColumn, '>=', $thisWeek)->count();
                $totalDownloads = Download::count();
                $downloadsToday = Download::whereDate($downloadTimestampColumn, $today)->count();
                $downloadsThisWeek = Download::where($downloadTimestampColumn, '>=', $thisWeek)->count();

                return [
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
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to load dashboard stats.');
    }

    /**
     * Get recent activity
     */
    public function recentActivity()
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:dashboard:recent_activity', now()->addMinutes(2), function () {
                $recentSongs = Song::with('artist:id,name,stage_name')
                    ->select('id', 'title', 'artist_id', 'created_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($song) => [
                        'id' => $song->id,
                        'title' => $song->title,
                        'artist' => ['name' => $song->artist?->stage_name ?? $song->artist?->name],
                        'created_at' => $song->created_at,
                    ]);

                $recentAlbums = Album::with('artist:id,name,stage_name')
                    ->select('id', 'title', 'artist_id', 'created_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($album) => [
                        'id' => $album->id,
                        'title' => $album->title,
                        'artist' => ['name' => $album->artist?->stage_name ?? $album->artist?->name],
                        'created_at' => $album->created_at,
                    ]);

                $recentUsers = User::select('id', 'username as name', 'email', 'created_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get();

                return [
                    'songs' => $recentSongs,
                    'albums' => $recentAlbums,
                    'users' => $recentUsers,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to load recent activity.');
    }

    private function resolvePlayHistoryTimestampColumn(): string
    {
        return Schema::hasColumn('play_histories', 'played_at') ? 'played_at' : 'created_at';
    }

    private function resolveDownloadTimestampColumn(): string
    {
        return Schema::hasColumn('downloads', 'downloaded_at') ? 'downloaded_at' : 'created_at';
    }
}
