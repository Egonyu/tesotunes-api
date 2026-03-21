<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArtistRevenue;
use App\Models\ModerationReport;
use App\Models\Song;
use App\Services\Revenue\StreamingRateService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminReportsController extends Controller
{
    use HandlesApiErrors;

    public function stats(): JsonResponse
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => ModerationReport::count(),
                    'pending' => ModerationReport::where('status', ModerationReport::STATUS_PENDING)->count(),
                    'reviewing' => ModerationReport::where('status', ModerationReport::STATUS_REVIEWING)->count(),
                    'resolved' => ModerationReport::where('status', ModerationReport::STATUS_RESOLVED)->count(),
                    'dismissed' => ModerationReport::where('status', ModerationReport::STATUS_DISMISSED)->count(),
                ],
            ]);
        }, 'Failed to fetch report stats.');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $filters = $this->validateModerationReportFilters($request);
            $perPage = min((int) ($filters['per_page'] ?? 20), 100);
            $reports = $this->buildModerationReportsQuery($filters)
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'records' => $reports->getCollection()->map(fn (ModerationReport $report) => $this->transformReport($report))->values(),
                    'filters' => $this->moderationReportFilterResponse($filters),
                    'export' => $this->moderationReportExportMetadata($filters),
                ],
                'meta' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                ],
            ]);
        }, 'Failed to fetch reports.');
    }

    public function exportReports(Request $request)
    {
        try {
            $filters = $this->validateModerationReportFilters($request);
            $reports = $this->buildModerationReportsQuery($filters)
                ->latest()
                ->get()
                ->map(fn (ModerationReport $report) => $this->transformReport($report));

            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Moderation Reports']);
            fputcsv($csv, ['Generated At', now()->toDateTimeString()]);
            fputcsv($csv, ['Status', $filters['status'] ?? '']);
            fputcsv($csv, ['Type', $filters['type'] ?? '']);
            fputcsv($csv, ['Search', $filters['search'] ?? '']);
            fputcsv($csv, []);
            fputcsv($csv, ['ID', 'Type', 'Reason', 'Description', 'Status', 'Priority', 'Reported By', 'Reported Item', 'Created At']);

            foreach ($reports as $report) {
                fputcsv($csv, [
                    $report['id'],
                    $report['type'],
                    $report['reason'],
                    $report['description'],
                    $report['status'],
                    $report['priority'],
                    $report['reported_by'],
                    $report['reported_item'],
                    $report['created_at'],
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$this->moderationReportExportMetadata($filters)['filename'].'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export reports.',
            ], 500);
        }
    }

    public function streamingPayouts(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $filters = $this->validateStreamingPayoutFilters($request);
            $page = max((int) ($filters['page'] ?? 1), 1);
            $perPage = min((int) ($filters['per_page'] ?? 20), 100);
            $report = $this->buildStreamingPayoutReport($filters);

            return response()->json([
                'success' => true,
                'data' => [
                    'filters' => $this->streamingPayoutFilterResponse($filters),
                    'streaming_configuration' => app(StreamingRateService::class)->getStreamingConfigurationSummary(),
                    'summary' => $report['summary'],
                    'breakdowns' => $report['breakdowns'],
                    'export' => $this->streamingPayoutExportMetadata($filters),
                    'records' => $report['records']->forPage($page, $perPage)->values(),
                ],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max((int) ceil(max($report['records']->count(), 1) / $perPage), 1),
                    'per_page' => $perPage,
                    'total' => $report['records']->count(),
                ],
            ]);
        }, 'Failed to fetch streaming payout report.');
    }

    public function exportStreamingPayouts(Request $request)
    {
        try {
            $filters = $this->validateStreamingPayoutFilters($request);
            $report = $this->buildStreamingPayoutReport($filters);
            $export = $this->streamingPayoutExportMetadata($filters);

            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Streaming Payout Report']);
            fputcsv($csv, ['Generated At', now()->toDateTimeString()]);
            fputcsv($csv, ['Date From', $filters['date_from'] ?? '']);
            fputcsv($csv, ['Date To', $filters['date_to'] ?? '']);
            fputcsv($csv, ['Rate Source', $filters['rate_source'] ?? '']);
            fputcsv($csv, ['Listener Plan', $filters['listener_plan_slug'] ?? '']);
            fputcsv($csv, []);
            fputcsv($csv, ['Summary']);
            fputcsv($csv, ['Total Stream Records', $report['summary']['total_stream_records']]);
            fputcsv($csv, ['Total Gross UGX', $report['summary']['total_gross_ugx']]);
            fputcsv($csv, ['Total Platform Fee UGX', $report['summary']['total_platform_fee_ugx']]);
            fputcsv($csv, ['Total Net UGX', $report['summary']['total_net_ugx']]);
            fputcsv($csv, []);
            fputcsv($csv, ['Records']);
            fputcsv($csv, [
                'Revenue ID',
                'Artist ID',
                'Song ID',
                'Song Title',
                'Rate Source',
                'Listener Plan Slug',
                'Listener Plan Name',
                'Listener Plan Tier',
                'Gross Amount UGX',
                'Platform Fee UGX',
                'Net Amount UGX',
                'Revenue Date',
                'Created At',
            ]);

            foreach ($report['records'] as $record) {
                fputcsv($csv, [
                    $record['id'],
                    $record['artist_id'],
                    $record['song_id'],
                    $record['song_title'],
                    $record['audit']['rate_source'] ?? '',
                    $record['audit']['listener_plan_slug'] ?? '',
                    $record['audit']['listener_plan_name'] ?? '',
                    $record['audit']['listener_plan_tier'] ?? '',
                    $record['gross_amount_ugx'],
                    $record['platform_fee_ugx'],
                    $record['net_amount_ugx'],
                    $record['revenue_date'],
                    $record['created_at'],
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export streaming payout report.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, ModerationReport $report): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $report) {
            $validated = $request->validate([
                'status' => 'required|in:reviewing,resolved,dismissed',
            ]);

            $report->fill([
                'status' => $validated['status'],
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Report status updated successfully.',
                'data' => $this->transformReport($report->fresh(['reporter:id,name,email'])),
            ]);
        }, 'Failed to update report status.');
    }

    private function transformReport(ModerationReport $report): array
    {
        return [
            'id' => $report->id,
            'type' => $report->type,
            'reason' => $report->reason,
            'description' => $report->description ?? '',
            'status' => $report->status,
            'priority' => $report->priority,
            'reported_by' => $report->reporter?->name ?: ($report->reporter?->email ?: 'System'),
            'reported_item' => $report->reported_item,
            'created_at' => optional($report->created_at)->toIso8601String(),
        ];
    }

    private function validateModerationReportFilters(Request $request): array
    {
        return $request->validate([
            'status' => 'nullable|string|max:50',
            'type' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function buildModerationReportsQuery(array $filters)
    {
        return ModerationReport::query()
            ->with(['reporter:id,name,email'])
            ->when(! empty($filters['status']) && $filters['status'] !== 'all',
                fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['type']) && $filters['type'] !== 'all',
                fn ($query) => $query->where('type', $filters['type']))
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = addcslashes((string) $filters['search'], '%_');

                $query->where(function ($nested) use ($search) {
                    $nested->where('reason', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reported_item', 'like', "%{$search}%")
                        ->orWhereHas('reporter', function ($reporterQuery) use ($search) {
                            $reporterQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            });
    }

    private function moderationReportFilterResponse(array $filters): array
    {
        return [
            'status' => $filters['status'] ?? null,
            'type' => $filters['type'] ?? null,
            'search' => $filters['search'] ?? null,
        ];
    }

    private function moderationReportExportMetadata(array $filters): array
    {
        $query = array_filter($this->moderationReportFilterResponse($filters), static fn ($value) => $value !== null && $value !== '');
        $filenameParts = ['moderation_reports'];

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $filenameParts[] = $filters['status'];
        }

        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $filenameParts[] = $filters['type'];
        }

        $filenameParts[] = now()->format('Y-m-d');

        return [
            'format' => 'csv',
            'filename' => implode('_', $filenameParts).'.csv',
            'url' => url('/api/admin/reports/export').(! empty($query) ? '?'.http_build_query($query) : ''),
            'filters' => $this->moderationReportFilterResponse($filters),
        ];
    }

    private function validateStreamingPayoutFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'rate_source' => 'nullable|string|max:100',
            'listener_plan_slug' => 'nullable|string|max:150',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function buildStreamingPayoutReport(array $filters): array
    {
        $records = ArtistRevenue::query()
            ->streaming()
            ->confirmed()
            ->where('sourceable_type', Song::class)
            ->when(! empty($filters['date_from']), fn ($query) => $query->whereDate('revenue_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('revenue_date', '<=', $filters['date_to']))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ArtistRevenue $revenue) => $this->transformStreamingPayoutRecord($revenue))
            ->filter(function (array $record) use ($filters) {
                if (! empty($filters['rate_source']) && ($record['audit']['rate_source'] ?? null) !== $filters['rate_source']) {
                    return false;
                }

                if (! empty($filters['listener_plan_slug']) && ($record['audit']['listener_plan_slug'] ?? null) !== $filters['listener_plan_slug']) {
                    return false;
                }

                return true;
            })
            ->values();

        return [
            'records' => $records,
            'summary' => [
                'total_stream_records' => $records->count(),
                'total_gross_ugx' => round((float) $records->sum('gross_amount_ugx'), 2),
                'total_platform_fee_ugx' => round((float) $records->sum('platform_fee_ugx'), 2),
                'total_net_ugx' => round((float) $records->sum('net_amount_ugx'), 2),
            ],
            'breakdowns' => [
                'rate_sources' => $this->buildRateSourceBreakdown($records),
                'listener_plans' => $this->buildPlanBreakdown($records),
            ],
        ];
    }

    private function buildRateSourceBreakdown(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (array $record) => $record['audit']['rate_source'] ?? 'unknown')
            ->map(fn (Collection $group, string $rateSource) => [
                'rate_source' => $rateSource,
                'stream_count' => $group->count(),
                'gross_amount_ugx' => round((float) $group->sum('gross_amount_ugx'), 2),
                'platform_fee_ugx' => round((float) $group->sum('platform_fee_ugx'), 2),
                'net_amount_ugx' => round((float) $group->sum('net_amount_ugx'), 2),
            ])
            ->sortByDesc('stream_count')
            ->values();
    }

    private function buildPlanBreakdown(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (array $record) => $record['audit']['listener_plan_slug'] ?? 'unassigned')
            ->map(function (Collection $group, string $planSlug) {
                $first = $group->first();

                return [
                    'listener_plan_slug' => $planSlug,
                    'listener_plan_name' => $first['audit']['listener_plan_name'] ?? null,
                    'listener_plan_tier' => $first['audit']['listener_plan_tier'] ?? null,
                    'stream_count' => $group->count(),
                    'gross_amount_ugx' => round((float) $group->sum('gross_amount_ugx'), 2),
                    'platform_fee_ugx' => round((float) $group->sum('platform_fee_ugx'), 2),
                    'net_amount_ugx' => round((float) $group->sum('net_amount_ugx'), 2),
                ];
            })
            ->sortByDesc('stream_count')
            ->values();
    }

    private function streamingPayoutFilterResponse(array $filters): array
    {
        return [
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'rate_source' => $filters['rate_source'] ?? null,
            'listener_plan_slug' => $filters['listener_plan_slug'] ?? null,
        ];
    }

    private function streamingPayoutExportMetadata(array $filters): array
    {
        $query = array_filter($this->streamingPayoutFilterResponse($filters), static fn ($value) => $value !== null && $value !== '');
        $filenameParts = ['streaming_payouts'];

        if (! empty($filters['rate_source'])) {
            $filenameParts[] = $filters['rate_source'];
        }

        if (! empty($filters['listener_plan_slug'])) {
            $filenameParts[] = $filters['listener_plan_slug'];
        }

        $filenameParts[] = now()->format('Y-m-d');

        return [
            'format' => 'csv',
            'filename' => implode('_', $filenameParts).'.csv',
            'url' => url('/api/admin/reports/streaming-payouts/export').(! empty($query) ? '?'.http_build_query($query) : ''),
            'filters' => $this->streamingPayoutFilterResponse($filters),
        ];
    }

    private function transformStreamingPayoutRecord(ArtistRevenue $revenue): array
    {
        $audit = $this->decodeStreamingAudit($revenue->notes);
        $songTitle = null;

        if ($revenue->sourceable_type === Song::class && $revenue->sourceable_id) {
            $songTitle = Song::query()->whereKey($revenue->sourceable_id)->value('title');
        }

        return [
            'id' => $revenue->id,
            'artist_id' => $revenue->artist_id,
            'song_id' => $revenue->sourceable_type === Song::class ? $revenue->sourceable_id : null,
            'song_title' => $songTitle,
            'gross_amount_ugx' => (float) $revenue->amount_ugx,
            'platform_fee_ugx' => (float) $revenue->platform_fee,
            'net_amount_ugx' => (float) $revenue->net_amount,
            'status' => $revenue->status,
            'revenue_date' => optional($revenue->revenue_date)->toDateString(),
            'created_at' => optional($revenue->created_at)->toIso8601String(),
            'audit' => $audit,
        ];
    }

    private function decodeStreamingAudit(?string $notes): array
    {
        if (! $notes) {
            return [];
        }

        $decoded = json_decode($notes, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : ['note' => $notes];
    }
}
