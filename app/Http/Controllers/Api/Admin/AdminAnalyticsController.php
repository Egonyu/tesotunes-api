<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Traits\HandlesApiErrors;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAnalyticsController extends Controller
{
    use HandlesApiErrors;

    public function overview(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            [$start, $end] = $this->resolveRange($request->query('range', $request->query('period', '30d')));
            $streamTimestampColumn = $this->resolvePlayHistoryTimestampColumn();

            $userCount = User::count();
            $songCount = Song::count();
            $totalRevenue = (float) Payment::query()
                ->where('status', 'completed')
                ->sum('amount');
            $streamCount = PlayHistory::query()
                ->whereBetween($streamTimestampColumn, [$start, $end])
                ->count();

            $previousStart = $this->shiftWindow($start, $end);
            $previousEnd = $start->copy()->subSecond();

            $previousUserCount = User::query()
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->count();
            $currentUserCount = User::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $previousSongCount = Song::query()
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->count();
            $currentSongCount = Song::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $previousRevenue = (float) Payment::query()
                ->where('status', 'completed')
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->sum('amount');
            $currentRevenue = (float) Payment::query()
                ->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $previousStreams = PlayHistory::query()
                ->whereBetween($streamTimestampColumn, [$previousStart, $previousEnd])
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'metrics' => [
                        [
                            'label' => 'Total Users',
                            'value' => number_format($userCount),
                            'change' => $this->percentChange($currentUserCount, $previousUserCount),
                            'icon' => 'Users',
                        ],
                        [
                            'label' => 'Songs Catalog',
                            'value' => number_format($songCount),
                            'change' => $this->percentChange($currentSongCount, $previousSongCount),
                            'icon' => 'Music',
                        ],
                        [
                            'label' => 'Revenue',
                            'value' => 'UGX '.number_format($totalRevenue, 0),
                            'change' => $this->percentChange($currentRevenue, $previousRevenue),
                            'icon' => 'DollarSign',
                        ],
                        [
                            'label' => 'Streams',
                            'value' => number_format($streamCount),
                            'change' => $this->percentChange($streamCount, $previousStreams),
                            'icon' => 'TrendingUp',
                        ],
                    ],
                    'top_countries' => $this->topCountries(),
                    'revenue_breakdown' => $this->revenueBreakdown($start, $end),
                    'streams_chart' => $this->streamsChart($start, $end),
                    'peak_hours' => $this->peakHours($start, $end),
                ],
            ]);
        }, 'Failed to load admin analytics.');
    }

    private function resolveRange(string $range): array
    {
        $end = Carbon::now();

        return match ($range) {
            '7d' => [$end->copy()->subDays(6)->startOfDay(), $end],
            '30d' => [$end->copy()->subDays(29)->startOfDay(), $end],
            '90d' => [$end->copy()->subDays(89)->startOfDay(), $end],
            '1y' => [$end->copy()->subYear()->addDay()->startOfDay(), $end],
            default => [$end->copy()->subDays(29)->startOfDay(), $end],
        };
    }

    private function shiftWindow(Carbon $start, Carbon $end): Carbon
    {
        $days = max($start->diffInDays($end), 0) + 1;

        return $start->copy()->subDays($days);
    }

    private function percentChange(float|int $current, float|int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function topCountries(): array
    {
        if (! Schema::hasColumn('users', 'country')) {
            return [];
        }

        $rows = User::query()
            ->select('country', DB::raw('COUNT(*) as total'))
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $grandTotal = max((int) $rows->sum('total'), 1);

        return $rows->map(fn ($row) => [
            'country' => (string) $row->country,
            'users' => (int) $row->total,
            'percentage' => round(((int) $row->total / $grandTotal) * 100, 1),
        ])->values()->all();
    }

    private function revenueBreakdown(Carbon $start, Carbon $end): array
    {
        $groupColumn = Schema::hasColumn('payments', 'payment_method')
            ? 'payment_method'
            : (Schema::hasColumn('payments', 'provider') ? 'provider' : null);

        if ($groupColumn === null) {
            $total = (float) Payment::query()
                ->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            if ($total <= 0) {
                return [];
            }

            return [[
                'source' => 'Completed Payments',
                'amount' => round($total, 2),
                'percentage' => 100,
            ]];
        }

        $rows = Payment::query()
            ->select($groupColumn, DB::raw('SUM(amount) as total_amount'))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy($groupColumn)
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        $grandTotal = max((float) $rows->sum('total_amount'), 1.0);

        return $rows->map(fn ($row) => [
            'source' => ucfirst(str_replace(['_', '-'], ' ', (string) ($row->{$groupColumn} ?: 'other'))),
            'amount' => round((float) $row->total_amount, 2),
            'percentage' => round(((float) $row->total_amount / $grandTotal) * 100, 1),
        ])->values()->all();
    }

    private function streamsChart(Carbon $start, Carbon $end): array
    {
        $streamTimestampColumn = $this->resolvePlayHistoryTimestampColumn();
        $rows = PlayHistory::query()
            ->selectRaw(sprintf('DATE(%s) as day, COUNT(*) as total', $streamTimestampColumn))
            ->whereBetween($streamTimestampColumn, [$start, $end])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $points = new Collection;
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $day = $cursor->toDateString();
            $row = $rows->get($day);

            $points->push([
                'date' => $cursor->format('M j'),
                'count' => (int) ($row->total ?? 0),
            ]);

            $cursor->addDay();
        }

        return $points->all();
    }

    private function peakHours(Carbon $start, Carbon $end): array
    {
        $streamTimestampColumn = $this->resolvePlayHistoryTimestampColumn();
        $rows = PlayHistory::query()
            ->selectRaw(sprintf('HOUR(%s) as hour_of_day, COUNT(*) as total', $streamTimestampColumn))
            ->whereBetween($streamTimestampColumn, [$start, $end])
            ->groupBy('hour_of_day')
            ->orderBy('hour_of_day')
            ->get()
            ->keyBy('hour_of_day');

        $max = max((int) $rows->max('total'), 1);

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'intensity' => round(((int) ($rows->get($hour)?->total ?? 0)) / $max, 2),
        ])->all();
    }

    private function resolvePlayHistoryTimestampColumn(): string
    {
        return Schema::hasColumn('play_histories', 'played_at') ? 'played_at' : 'created_at';
    }
}
