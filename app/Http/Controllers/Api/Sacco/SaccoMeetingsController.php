<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoMeeting;
use App\Models\Sacco\SaccoMeetingAttendance;
use App\Models\Sacco\SaccoMember;
use App\Services\Sacco\SaccoGovernanceNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoMeetingsController extends Controller
{
    public function __construct(private readonly SaccoGovernanceNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        $meetings = SaccoMeeting::query()
            ->when($request->filled('status') && $request->input('status') !== 'all', function ($query) use ($request) {
                $status = $request->input('status');
                $query->where('status', $status === 'ongoing' ? 'in_progress' : $status);
            })
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (SaccoMeeting $meeting) => $this->formatMeeting($meeting, $member));

        return response()->json([
            'data' => $meetings,
        ]);
    }

    public function show(Request $request, SaccoMeeting $meeting): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        return response()->json([
            'data' => $this->formatMeeting($meeting, $member, true),
        ]);
    }

    public function rsvp(Request $request, SaccoMeeting $meeting): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        $validated = $request->validate([
            'attending' => 'required|boolean',
            'proxy_name' => 'nullable|string|max:255',
        ]);

        if (! $validated['attending']) {
            SaccoMeetingAttendance::where('meeting_id', $meeting->id)
                ->where('member_id', $member->id)
                ->delete();

            $this->syncAttendeesCount($meeting);

            return response()->json([
                'message' => 'RSVP updated. You are no longer attending this meeting.',
                'data' => $this->formatMeeting($meeting->fresh(), $member),
            ]);
        }

        SaccoMeetingAttendance::updateOrCreate(
            [
                'meeting_id' => $meeting->id,
                'member_id' => $member->id,
            ],
            [
                'checked_in_at' => now(),
                'proxy' => filled($validated['proxy_name'] ?? null),
                'proxy_name' => $validated['proxy_name'] ?? null,
            ]
        );

        $this->syncAttendeesCount($meeting);
        $this->notifications->notifyRsvpConfirmed($meeting->fresh(), $member);

        return response()->json([
            'message' => 'RSVP updated successfully.',
            'data' => $this->formatMeeting($meeting->fresh(), $member),
        ]);
    }

    private function getAuthenticatedMember(Request $request): SaccoMember
    {
        return SaccoMember::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function syncAttendeesCount(SaccoMeeting $meeting): void
    {
        $meeting->forceFill([
            'attendees_count' => SaccoMeetingAttendance::where('meeting_id', $meeting->id)->count(),
        ])->save();
    }

    private function formatMeeting(SaccoMeeting $meeting, SaccoMember $member, bool $includeDetails = false): array
    {
        $attendance = SaccoMeetingAttendance::where('meeting_id', $meeting->id)
            ->where('member_id', $member->id)
            ->first();

        $data = [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'agenda' => $meeting->agenda,
            'description' => $meeting->description,
            'meeting_type' => $meeting->meeting_type,
            'meeting_date' => $meeting->scheduled_at?->toISOString(),
            'scheduled_at' => $meeting->scheduled_at?->toISOString(),
            'location' => $meeting->location,
            'is_online' => empty($meeting->location),
            'status' => $meeting->status === 'in_progress' ? 'ongoing' : $meeting->status,
            'attendees_count' => (int) $meeting->attendees_count,
            'quorum_required' => (int) $meeting->quorum_required,
            'quorum_met' => $meeting->has_quorum,
            'is_attending' => (bool) $attendance,
            'proxy_name' => $attendance?->proxy_name,
            'minutes' => $includeDetails ? $meeting->minutes : null,
            'resolutions' => $meeting->resolutions ?? [],
        ];

        if ($includeDetails) {
            $data['attendance'] = SaccoMeetingAttendance::query()
                ->where('meeting_id', $meeting->id)
                ->with('member.user:id,username,email')
                ->get()
                ->map(fn (SaccoMeetingAttendance $row) => [
                    'id' => $row->id,
                    'member_id' => $row->member_id,
                    'member_name' => $row->member?->user?->username ?? $row->member?->member_number,
                    'checked_in_at' => $row->checked_in_at?->toISOString(),
                    'proxy' => (bool) $row->proxy,
                    'proxy_name' => $row->proxy_name,
                ])->values();
        }

        return $data;
    }
}
