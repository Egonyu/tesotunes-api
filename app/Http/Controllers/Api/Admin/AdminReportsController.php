<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationReport;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $perPage = min((int) $request->input('per_page', 20), 100);

            $reports = ModerationReport::query()
                ->with(['reporter:id,name,email'])
                ->when($request->filled('status') && $request->string('status')->value() !== 'all',
                    fn ($query) => $query->where('status', $request->string('status')->value()))
                ->when($request->filled('type') && $request->string('type')->value() !== 'all',
                    fn ($query) => $query->where('type', $request->string('type')->value()))
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = addcslashes($request->string('search')->value(), '%_');

                    $query->where(function ($nested) use ($search) {
                        $nested->where('reason', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('reported_item', 'like', "%{$search}%")
                            ->orWhereHas('reporter', function ($reporterQuery) use ($search) {
                                $reporterQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $reports->getCollection()->map(fn (ModerationReport $report) => $this->transformReport($report))->values(),
                'meta' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                ],
            ]);
        }, 'Failed to fetch reports.');
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
}
