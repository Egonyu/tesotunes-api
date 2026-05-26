<?php

namespace App\Modules\Sacco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if(! $user->saccoMember, 404, 'SACCO membership not found.');

        $limit = min((int) $request->input('limit', 20), 100);

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->where('category', 'sacco')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'channel' => 'in_app',
                'data' => $n->data ?? [],
                'read_at' => $n->read_at?->toISOString(),
                'sent_at' => $n->created_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ])->values();

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'unread_count' => Notification::query()
                    ->where('user_id', $user->id)
                    ->where('category', 'sacco')
                    ->where('is_read', false)
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('category', 'sacco')
            ->findOrFail($id);

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
        abort_if(! $request->user()->saccoMember, 404, 'SACCO membership not found.');

        Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('category', 'sacco')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
