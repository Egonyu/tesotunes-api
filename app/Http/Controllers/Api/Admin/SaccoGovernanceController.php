<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoMeeting;
use App\Models\Sacco\SaccoMeetingAttendance;
use App\Models\Sacco\SaccoMember;
use App\Services\Sacco\SaccoGovernanceNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoGovernanceController extends Controller
{
    public function __construct(private readonly SaccoGovernanceNotificationService $notifications) {}

    public function meetings(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $meetings = SaccoMeeting::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = addcslashes((string) $request->input('search'), '%_');
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('agenda', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('minutes', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status') && $request->input('status') !== 'all', function ($query) use ($request) {
                $status = $request->input('status');
                $query->where('status', $status === 'ongoing' ? 'in_progress' : $status);
            })
            ->when($request->filled('meeting_type') && $request->input('meeting_type') !== 'all', function ($query) use ($request) {
                $query->where('meeting_type', $request->input('meeting_type'));
            })
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('scheduled_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('scheduled_at', '<=', $request->input('date_to')))
            ->when($request->boolean('has_resolutions'), function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('minutes')->orWhereNotNull('resolutions');
                });
            })
            ->orderByDesc('scheduled_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($meetings->items())->map(fn (SaccoMeeting $meeting) => $this->formatMeeting($meeting))->values(),
            'meta' => [
                'current_page' => $meetings->currentPage(),
                'last_page' => $meetings->lastPage(),
                'per_page' => $meetings->perPage(),
                'total' => $meetings->total(),
            ],
        ]);
    }

    public function showMeeting(SaccoMeeting $meeting): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting, true),
        ]);
    }

    public function attendanceSummary(Request $request): JsonResponse
    {
        $completedMeetings = SaccoMeeting::query()
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('scheduled_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('scheduled_at', '<=', $request->input('date_to')))
            ->orderByDesc('scheduled_at')
            ->get(['id', 'title', 'scheduled_at', 'status']);

        $meetingIds = $completedMeetings->pluck('id');
        $attendances = SaccoMeetingAttendance::query()
            ->whereIn('meeting_id', $meetingIds)
            ->with('member.user:id,username,email')
            ->get()
            ->groupBy('member_id');

        $rows = SaccoMember::query()
            ->where('status', SaccoMember::STATUS_ACTIVE)
            ->with('user:id,username,email')
            ->get()
            ->map(function (SaccoMember $member) use ($attendances, $completedMeetings) {
                $memberAttendances = $attendances->get($member->id, collect());
                $attendedMeetingIds = $memberAttendances->pluck('meeting_id')->unique();
                $attended = $attendedMeetingIds->count();
                $totalMeetings = $completedMeetings->count();
                $missed = max(0, $totalMeetings - $attended);
                $recentMeetings = $completedMeetings->take(3);
                $recentMissed = $recentMeetings->filter(fn ($meeting) => ! $attendedMeetingIds->contains($meeting->id))->count();

                return [
                    'member_id' => $member->id,
                    'member_name' => $member->user?->username ?? $member->member_number,
                    'email' => $member->user?->email,
                    'attendance_rate' => $totalMeetings > 0 ? round(($attended / $totalMeetings) * 100, 1) : 0,
                    'meetings_attended' => $attended,
                    'meetings_missed' => $missed,
                    'recent_missed' => $recentMissed,
                    'attendance_flag' => $recentMissed >= 2 ? 'follow_up' : ($missed > 0 ? 'watch' : 'healthy'),
                    'last_attended_at' => optional($memberAttendances->sortByDesc('checked_in_at')->first()?->checked_in_at)->toISOString(),
                ];
            })
            ->sortBy([
                ['recent_missed', 'desc'],
                ['meetings_missed', 'desc'],
                ['member_name', 'asc'],
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'total_meetings' => $completedMeetings->count(),
                'flagged_members' => $rows->where('attendance_flag', 'follow_up')->count(),
            ],
        ]);
    }

    public function storeMeeting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'meeting_type' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'agenda' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'scheduled_at' => 'required|date',
            'quorum_required' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
            'minutes' => 'nullable|string',
            'resolutions' => 'nullable|array',
        ]);

        $meeting = SaccoMeeting::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        $this->notifications->notifyMeetingScheduled($meeting);

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting, true),
            'message' => 'Governance meeting created successfully.',
        ], 201);
    }

    public function updateMeeting(Request $request, SaccoMeeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'meeting_type' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'agenda' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'scheduled_at' => 'nullable|date',
            'quorum_required' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
            'minutes' => 'nullable|string',
            'resolutions' => 'nullable|array',
        ]);

        $meeting->update($validated);
        $meeting = $meeting->fresh();

        $this->notifications->notifyMeetingUpdated($meeting);

        $publishedResolutionFields = array_key_exists('minutes', $validated) || array_key_exists('resolutions', $validated);
        if ($publishedResolutionFields && (! empty($meeting->resolutions) || filled($meeting->minutes))) {
            $this->notifications->notifyResolutionsPublished($meeting);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting, true),
            'message' => 'Governance meeting updated successfully.',
        ]);
    }

    public function destroyMeeting(SaccoMeeting $meeting): JsonResponse
    {
        $meeting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Governance meeting deleted successfully.',
        ]);
    }

    public function attendance(SaccoMeeting $meeting): JsonResponse
    {
        $rows = SaccoMeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->with('member.user:id,username,email')
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(fn (SaccoMeetingAttendance $row) => [
                'id' => $row->id,
                'member_id' => $row->member_id,
                'member_name' => $row->member?->user?->username ?? $row->member?->member_number,
                'email' => $row->member?->user?->email,
                'checked_in_at' => $row->checked_in_at?->toISOString(),
                'proxy' => (bool) $row->proxy,
                'proxy_name' => $row->proxy_name,
            ])->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function markAttendance(Request $request, SaccoMeeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:sacco_members,id',
            'attending' => 'required|boolean',
            'proxy_name' => 'nullable|string|max:255',
        ]);

        SaccoMember::findOrFail($validated['member_id']);

        if (! $validated['attending']) {
            SaccoMeetingAttendance::where('meeting_id', $meeting->id)
                ->where('member_id', $validated['member_id'])
                ->delete();
        } else {
            SaccoMeetingAttendance::updateOrCreate(
                [
                    'meeting_id' => $meeting->id,
                    'member_id' => $validated['member_id'],
                ],
                [
                    'checked_in_at' => now(),
                    'proxy' => filled($validated['proxy_name'] ?? null),
                    'proxy_name' => $validated['proxy_name'] ?? null,
                ]
            );
        }

        $meeting->forceFill([
            'attendees_count' => SaccoMeetingAttendance::where('meeting_id', $meeting->id)->count(),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting->fresh(), true),
            'message' => 'Attendance updated successfully.',
        ]);
    }

    private function formatMeeting(SaccoMeeting $meeting, bool $includeAttendance = false): array
    {
        $data = [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'meeting_type' => $meeting->meeting_type,
            'description' => $meeting->description,
            'agenda' => $meeting->agenda,
            'meeting_date' => $meeting->scheduled_at?->toISOString(),
            'scheduled_at' => $meeting->scheduled_at?->toISOString(),
            'location' => $meeting->location,
            'is_online' => empty($meeting->location),
            'status' => $meeting->status === 'in_progress' ? 'ongoing' : $meeting->status,
            'quorum_required' => (int) $meeting->quorum_required,
            'quorum_met' => $meeting->has_quorum,
            'attendees_count' => (int) $meeting->attendees_count,
            'minutes' => $meeting->minutes,
            'resolutions' => $meeting->resolutions ?? [],
            'created_at' => $meeting->created_at?->toISOString(),
            'updated_at' => $meeting->updated_at?->toISOString(),
        ];

        if ($includeAttendance) {
            $data['attendance'] = SaccoMeetingAttendance::query()
                ->where('meeting_id', $meeting->id)
                ->with('member.user:id,username,email')
                ->get()
                ->map(fn (SaccoMeetingAttendance $row) => [
                    'id' => $row->id,
                    'member_id' => $row->member_id,
                    'member_name' => $row->member?->user?->username ?? $row->member?->member_number,
                    'email' => $row->member?->user?->email,
                    'checked_in_at' => $row->checked_in_at?->toISOString(),
                    'proxy' => (bool) $row->proxy,
                    'proxy_name' => $row->proxy_name,
                ])->values();
        }

        return $data;
    }
}
