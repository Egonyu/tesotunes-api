<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoBoardMeeting;
use App\Models\Sacco\SaccoBoardMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoBoardMeetingsController extends Controller
{
    /**
     * GET /api/admin/sacco/board-members — list board members
     */
    public function boardMembers(Request $request): JsonResponse
    {
        $query = SaccoBoardMember::with('member.user');

        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        } else {
            $query->active();
        }

        $members = $query->orderBy('position')->get();

        return response()->json([
            'success' => true,
            'data' => $members->map(fn ($bm) => [
                'id' => $bm->id,
                'name' => $bm->member?->user?->username
                    ?? $bm->member?->user?->name
                    ?? $bm->member?->member_number
                    ?? 'Unknown',
                'email' => $bm->member?->user?->email ?? null,
                'role' => $bm->position_display,
                'position' => $bm->position,
                'appointed_at' => $bm->term_start_date?->toISOString(),
                'term_end' => $bm->term_end_date?->toISOString(),
                'status' => $bm->is_active ? 'active' : 'inactive',
            ])->values(),
        ]);
    }

    /**
     * GET /api/admin/sacco/board-meetings — list meetings
     */
    public function index(Request $request): JsonResponse
    {
        $query = SaccoBoardMeeting::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        $meetings = $query->orderByDesc('scheduled_at')->paginate($perPage);

        $meetings->getCollection()->transform(fn ($m) => $this->formatMeeting($m));

        return response()->json([
            'success' => true,
            'data' => $meetings->items(),
            'meta' => [
                'current_page' => $meetings->currentPage(),
                'last_page' => $meetings->lastPage(),
                'per_page' => $meetings->perPage(),
                'total' => $meetings->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/sacco/board-meetings/{id} — show single meeting
     */
    public function show($id): JsonResponse
    {
        $meeting = SaccoBoardMeeting::with('attendance')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting),
        ]);
    }

    /**
     * POST /api/admin/sacco/board-meetings — create meeting
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'agenda' => 'nullable|string|max:5000',
            'scheduled_at' => 'required|date|after:now',
            'venue' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
        ]);

        $meeting = SaccoBoardMeeting::create([
            'title' => $validated['title'],
            'agenda' => $validated['agenda'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'venue' => $validated['venue'] ?? null,
            'status' => $validated['status'] ?? 'scheduled',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting),
            'message' => 'Board meeting created successfully.',
        ], 201);
    }

    /**
     * PUT /api/admin/sacco/board-meetings/{id} — update meeting
     */
    public function update(Request $request, $id): JsonResponse
    {
        $meeting = SaccoBoardMeeting::findOrFail($id);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'agenda' => 'nullable|string|max:5000',
            'scheduled_at' => 'nullable|date',
            'venue' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
            'minutes' => 'nullable|string',
            'decisions' => 'nullable|array',
        ]);

        $meeting->update(array_filter($validated, fn ($v) => $v !== null));

        return response()->json([
            'success' => true,
            'data' => $this->formatMeeting($meeting->fresh()),
            'message' => 'Board meeting updated successfully.',
        ]);
    }

    /**
     * DELETE /api/admin/sacco/board-meetings/{id} — delete meeting
     */
    public function destroy($id): JsonResponse
    {
        $meeting = SaccoBoardMeeting::findOrFail($id);

        if ($meeting->status === 'completed') {
            return response()->json([
                'message' => 'Cannot delete a completed meeting.',
            ], 422);
        }

        $meeting->delete();

        return response()->json([
            'message' => 'Board meeting deleted successfully.',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function formatMeeting(SaccoBoardMeeting $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'agenda' => $m->agenda,
            'meeting_date' => $m->scheduled_at?->toISOString(),
            'scheduled_at' => $m->scheduled_at?->toISOString(),
            'location' => $m->venue,
            'venue' => $m->venue,
            'is_online' => empty($m->venue),
            'status' => $m->status === 'in_progress' ? 'ongoing' : $m->status,
            'quorum_met' => $m->attendance_rate > 50,
            'attendees_count' => $m->attendance()->count(),
            'minutes' => $m->minutes,
            'minutes_url' => null,
            'decisions' => $m->decisions,
            'created_at' => $m->created_at?->toISOString(),
            'updated_at' => $m->updated_at?->toISOString(),
        ];
    }
}
