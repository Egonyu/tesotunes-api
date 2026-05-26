<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification API Controller
 */
class NotificationController extends Controller
{
    /**
     * Get user's notifications
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        // Filter by type
        if ($type = $request->type) {
            $query->byType($type);
        }

        // Filter by read status
        if ($request->has('unread') && $request->unread) {
            $query->unread();
        }

        $notifications = $query->paginate($this->getPerPage($request));

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'unread_count' => Notification::getUnreadCount($request->user()),
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::markAllAsRead($request->user());

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted.',
        ]);
    }

    /**
     * Get notification preferences
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $setting = UserSetting::firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'in_app' => true,
                'email' => (bool) $setting->email_notifications,
                'sms' => (bool) $setting->sms_notifications,
                'push' => (bool) $setting->push_notifications,
            ],
        ]);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|boolean',
            'sms' => 'required|boolean',
            'push' => 'required|boolean',
        ]);

        UserSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'email_notifications' => $validated['email'],
                'sms_notifications' => $validated['sms'],
                'push_notifications' => $validated['push'],
            ]
        );

        return response()->json([
            'message' => 'Preferences updated successfully.',
        ]);
    }
}
