<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Download;
use App\Models\Payment;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\HandlesApiErrors;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    use HandlesApiErrors;

    private function hasSongColumn(string $column): bool
    {
        static $songColumns = null;

        $songColumns ??= array_flip(Schema::getColumnListing('songs'));

        return isset($songColumns[$column]);
    }

    /**
     * Get dashboard statistics
     */
    public function stats(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $liveMode = $request->boolean('live');
            $cacheTtl = $liveMode ? now()->addSeconds(20) : now()->addMinutes(5);
            $cacheKey = $liveMode ? 'admin:dashboard:stats:live' : 'admin:dashboard:stats';

            $data = Cache::remember($cacheKey, $cacheTtl, function () {
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
                $pendingReview = Song::whereIn('status', ['pending', 'pending_review'])->count();
                $draftSongs = Song::where('status', 'draft')->count();
                $isrcAssignedSongs = Song::whereNotNull('isrc_code')->count();
                $songHasRightsColumns = $this->hasSongColumn('master_ownership_percentage') && $this->hasSongColumn('publishing_ownership_percentage');

                $isrcReadySongsQuery = Song::query()
                    ->whereNull('isrc_code')
                    ->whereNotNull('artist_id')
                    ->whereNotNull('title')
                    ->where('duration_seconds', '>', 0)
                    ->where(function ($query) {
                        $query
                            ->whereNotNull('audio_file_original')
                            ->orWhereNotNull('audio_file_320')
                            ->orWhereNotNull('audio_file_128');
                    });

                if ($songHasRightsColumns) {
                    $isrcReadySongsQuery->whereRaw('COALESCE(master_ownership_percentage, 0) + COALESCE(publishing_ownership_percentage, 0) <= 200');
                }

                $isrcReadySongs = $isrcReadySongsQuery
                    ->where(function ($query) {
                        $query
                            ->whereIn('distribution_status', ['approved', 'distributed'])
                            ->orWhere(function ($publishedQuery) {
                                $publishedQuery
                                    ->where('status', 'published')
                                    ->whereNotNull('approved_at');
                            });
                    })
                    ->count();
                $isrcBlockedSongsQuery = Song::query()
                    ->whereNull('isrc_code')
                    ->where(function ($query) {
                        $query
                            ->whereIn('distribution_status', ['approved', 'distributed'])
                            ->orWhere(function ($publishedQuery) {
                                $publishedQuery
                                    ->where('status', 'published')
                                    ->whereNotNull('approved_at');
                            });
                    })
                    ->where(function ($query) use ($songHasRightsColumns) {
                        $query
                            ->whereNull('artist_id')
                            ->orWhereNull('title')
                            ->orWhere('duration_seconds', '<=', 0)
                            ->orWhere(function ($audioMissingQuery) {
                                $audioMissingQuery
                                    ->whereNull('audio_file_original')
                                    ->whereNull('audio_file_320')
                                    ->whereNull('audio_file_128');
                            });

                        if ($songHasRightsColumns) {
                            $query->orWhereRaw('COALESCE(master_ownership_percentage, 0) + COALESCE(publishing_ownership_percentage, 0) > 200');
                        }
                    });

                $isrcBlockedSongs = $isrcBlockedSongsQuery->count();

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

                $totalStreamRevenue = ArtistRevenue::query()
                    ->where('revenue_type', ArtistRevenue::TYPE_STREAM)
                    ->whereIn('status', [ArtistRevenue::STATUS_CONFIRMED, ArtistRevenue::STATUS_PAID])
                    ->sum('net_amount');

                $totalDownloadRevenue = ArtistRevenue::query()
                    ->where('revenue_type', ArtistRevenue::TYPE_DOWNLOAD)
                    ->whereIn('status', [ArtistRevenue::STATUS_CONFIRMED, ArtistRevenue::STATUS_PAID])
                    ->sum('net_amount');

                $revenueSources = Payment::query()
                    ->where('status', 'completed')
                    ->select('payment_type', DB::raw('COUNT(*) as transactions'), DB::raw('SUM(amount) as total_ugx'))
                    ->groupBy('payment_type')
                    ->orderByDesc('total_ugx')
                    ->limit(6)
                    ->get()
                    ->map(fn ($row) => [
                        'source' => (string) ($row->payment_type ?: 'unknown'),
                        'transactions' => (int) $row->transactions,
                        'total_ugx' => round((float) $row->total_ugx, 2),
                    ]);

                $streamSources = ArtistRevenue::query()
                    ->whereIn('status', [ArtistRevenue::STATUS_CONFIRMED, ArtistRevenue::STATUS_PAID])
                    ->select('revenue_type', DB::raw('COUNT(*) as entries'), DB::raw('SUM(net_amount) as total_ugx'))
                    ->groupBy('revenue_type')
                    ->orderByDesc('total_ugx')
                    ->get()
                    ->map(fn ($row) => [
                        'source' => (string) ($row->revenue_type ?: 'unknown'),
                        'entries' => (int) $row->entries,
                        'total_ugx' => round((float) $row->total_ugx, 2),
                    ]);

                $perArtistSongTotalsQuery = ArtistRevenue::query()
                    ->from('artist_revenues as ar')
                    ->join('artists as a', 'a.id', '=', 'ar.artist_id')
                    ->leftJoin('songs as s', 's.id', '=', 'ar.song_id')
                    ->whereIn('ar.status', [ArtistRevenue::STATUS_CONFIRMED, ArtistRevenue::STATUS_PAID])
                    ->selectRaw('ar.artist_id')
                    ->selectRaw('COALESCE(a.stage_name, a.name, CONCAT("Artist #", a.id)) as artist_name')
                    ->selectRaw('SUM(ar.net_amount) as total_ugx')
                    ->selectRaw('SUM(CASE WHEN ar.revenue_type = ? THEN ar.net_amount ELSE 0 END) as stream_ugx', [ArtistRevenue::TYPE_STREAM])
                    ->selectRaw('SUM(CASE WHEN ar.revenue_type = ? THEN ar.net_amount ELSE 0 END) as download_ugx', [ArtistRevenue::TYPE_DOWNLOAD])
                    ->selectRaw('SUM(CASE WHEN ar.revenue_date >= ? THEN ar.net_amount ELSE 0 END) as last_30_days_ugx', [Carbon::now()->subDays(30)->toDateString()])
                    ->selectRaw('ar.song_id')
                    ->selectRaw('COALESCE(s.title, "Unknown song") as song_title')
                    ->groupBy('ar.artist_id', 'artist_name', 'ar.song_id', 'song_title')
                    ->orderByDesc('total_ugx')
                    ->limit(50);

                $perArtistSongTotals = $perArtistSongTotalsQuery
                    ->get()
                    ->map(fn ($row) => [
                        'artist_id' => (int) $row->artist_id,
                        'artist_name' => (string) $row->artist_name,
                        'song_id' => $row->song_id ? (int) $row->song_id : null,
                        'song_title' => (string) $row->song_title,
                        'total_ugx' => round((float) $row->total_ugx, 2),
                        'stream_ugx' => round((float) $row->stream_ugx, 2),
                        'download_ugx' => round((float) $row->download_ugx, 2),
                        'last_30_days_ugx' => round((float) $row->last_30_days_ugx, 2),
                    ]);

                $timeseries = collect(range(13, 0))
                    ->map(function (int $daysAgo) use ($playHistoryTimestampColumn) {
                        $day = Carbon::today()->subDays($daysAgo);

                        $plays = PlayHistory::whereDate($playHistoryTimestampColumn, $day)->count();
                        $grossRevenue = Payment::query()
                            ->where('status', 'completed')
                            ->whereDate('completed_at', $day)
                            ->sum('amount');
                        $artistRevenue = ArtistRevenue::query()
                            ->whereIn('status', [ArtistRevenue::STATUS_CONFIRMED, ArtistRevenue::STATUS_PAID])
                            ->whereDate('revenue_date', $day)
                            ->sum('net_amount');

                        return [
                            'date' => $day->toDateString(),
                            'streams' => (int) $plays,
                            'gross_revenue_ugx' => round((float) $grossRevenue, 2),
                            'artist_revenue_ugx' => round((float) $artistRevenue, 2),
                        ];
                    })
                    ->values();

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
                        'isrc_assigned' => $isrcAssignedSongs,
                        'isrc_ready' => $isrcReadySongs,
                        'isrc_blocked' => $isrcBlockedSongs,
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
                    'stream_financials' => [
                        'total_streams' => $totalPlays,
                        'stream_revenue_ugx' => round((float) $totalStreamRevenue, 2),
                        'download_revenue_ugx' => round((float) $totalDownloadRevenue, 2),
                        'combined_artist_revenue_ugx' => round((float) ($totalStreamRevenue + $totalDownloadRevenue), 2),
                    ],
                    'sources' => [
                        'revenue' => $revenueSources,
                        'streaming' => $streamSources,
                    ],
                    'per_artist_song_totals' => $perArtistSongTotals,
                    'timeseries_14d' => $timeseries,
                    'generated_at' => now()->toIso8601String(),
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
        return 'played_at';
    }

    private function resolveDownloadTimestampColumn(): string
    {
        return 'downloaded_at';
    }
}
