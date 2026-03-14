<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->saccoMember;

        abort_if(! $member, 404, 'SACCO membership not found.');

        $notifications = SaccoNotification::query()
            ->where('member_id', $member->id)
            ->latest()
            ->limit(min((int) $request->input('limit', 20), 100))
            ->get()
            ->map(fn (SaccoNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'channel' => $notification->channel,
                'data' => $notification->data ?? [],
                'read_at' => $notification->read_at?->toISOString(),
                'sent_at' => $notification->sent_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ])->values();

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'unread_count' => SaccoNotification::query()
                    ->where('member_id', $member->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, SaccoNotification $notification): JsonResponse
    {
        $member = $request->user()->saccoMember;

        abort_unless($member && $notification->member_id === $member->id, 404);

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => [
                'id' => $notification->id,
                'read_at' => $notification->read_at?->toISOString(),
            ],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $member = $request->user()->saccoMember;

        abort_if(! $member, 404, 'SACCO membership not found.');

        SaccoNotification::query()
            ->where('member_id', $member->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
