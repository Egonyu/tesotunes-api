<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\UserSetting;
use App\Services\CrossModuleNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected CrossModuleNotificationService $notificationService;

    public function __construct(CrossModuleNotificationService $notificationService)
    {
        $this->middleware('auth:sanctum');
        $this->notificationService = $notificationService;
    }

    /**
     * Get user's notifications with pagination.
     *
     * GET /api/notifications?module=music&unread_only=true
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Notification::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('module')) {
            $query->where(function ($subQuery) use ($request) {
                $subQuery->where('category', $request->module)
                    ->orWhereJsonContains('data->module', $request->module);
            });
        }

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        return NotificationResource::collection(
            $query->paginate($this->getPerPage($request))
        );
    }

    /**
     * Get unread notification counts by module.
     *
     * GET /api/notifications/unread-counts
     */
    public function unreadCounts()
    {
        $user = Auth::user();

        return response()->json([
            'data' => $this->notificationService->getUnreadCountByModule($user),
        ]);
    }

    /**
     * Get recent notifications for dashboard widget.
     *
     * GET /api/notifications/recent?limit=5
     */
    public function recent(Request $request)
    {
        $user = Auth::user();
        $limit = min($request->integer('limit', 5), 50);

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return NotificationResource::collection($notifications);
    }

    /**
     * Get notification settings/preferences.
     *
     * GET /api/notifications/settings
     */
    public function settings()
    {
        $user = Auth::user();

        $settings = [
            'email_notifications' => [
                'music' => ['song_approved', 'distribution_live', 'royalty_payment'],
                'podcast' => ['episode_published', 'new_subscriber'],
                'store' => ['order_received', 'payment_received'],
                'sacco' => ['loan_approved', 'payment_due'],
            ],
            'push_notifications' => [
                'music' => ['song_approved', 'distribution_live'],
                'podcast' => ['new_subscriber'],
                'store' => ['order_received'],
                'sacco' => ['loan_approved'],
            ],
            'in_app_notifications' => [
                'music' => true,
                'podcast' => true,
                'store' => true,
                'sacco' => true,
            ],
        ];

        return response()->json(['data' => $settings]);
    }

    /**
     * Update notification settings/preferences.
     *
     * PUT /api/notifications/settings
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'email_notifications' => 'array',
            'push_notifications' => 'array',
            'in_app_notifications' => 'array',
        ]);

        $user = Auth::user();

        UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email_notifications' => ! empty($request->input('email_notifications')),
                'push_notifications' => ! empty($request->input('push_notifications')),
                'notification_preferences' => [
                    'email_notifications' => $request->input('email_notifications', []),
                    'push_notifications' => $request->input('push_notifications', []),
                    'in_app_notifications' => $request->input('in_app_notifications', []),
                ],
            ]
        );

        return response()->json(['message' => 'Notification settings updated.']);
    }

    /**
     * Mark all notifications as read.
     *
     * POST /api/notifications/mark-all-read?module=music
     */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();

        if ($request->filled('module')) {
            $this->notificationService->markModuleNotificationsAsRead($user, $request->module);
        } else {
            Notification::query()
                ->where('user_id', $user->id)
                ->where('is_read', false)
                ->update(['read_at' => now(), 'is_read' => true]);
        }

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    /**
     * Mark a single notification as read.
     *
     * POST /api/notifications/{notification}/mark-read
     */
    public function markAsRead(string $notificationId)
    {
        $notification = Notification::query()
            ->where('user_id', Auth::id())
            ->where('id', $notificationId)
            ->where('is_read', false)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    /**
     * Delete a notification.
     *
     * DELETE /api/notifications/{notification}
     */
    public function destroy(string $notificationId)
    {
        $notification = Notification::query()
            ->where('user_id', Auth::id())
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->delete();

        return response()->json(null, 204);
    }

    /**
     * Get notification analytics (admin only).
     *
     * GET /api/notifications/analytics?period=30
     */
    public function analytics(Request $request)
    {
        $period = $request->integer('period', 30);

        $totalSent = Notification::where('created_at', '>=', now()->subDays($period))->count();
        $totalRead = Notification::where('created_at', '>=', now()->subDays($period))
            ->where('is_read', true)->count();

        return response()->json([
            'data' => [
                'total_sent' => $totalSent,
                'total_read' => $totalRead,
                'read_rate' => $totalSent > 0 ? round(($totalRead / $totalSent) * 100, 1) : 0,
                'period_days' => $period,
            ],
        ]);
    }

    /**
     * Preview notification template (admin only).
     *
     * POST /api/notifications/preview
     */
    public function preview(Request $request)
    {
        $request->validate([
            'module' => 'required|string',
            'type' => 'required|string',
            'data' => 'array',
        ]);

        $tempNotification = new \App\Notifications\CrossModuleNotification(
            $request->module,
            $request->type,
            'Preview Title',
            'Preview Message',
            $request->get('data', [])
        );

        return response()->json([
            'data' => [
                'preview' => $tempNotification->toArray(Auth::user()),
                'channels' => $tempNotification->via(Auth::user()),
            ],
        ]);
    }
}
