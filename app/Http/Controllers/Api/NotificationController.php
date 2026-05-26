<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CrossModuleNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $counts = $this->notificationService->getUnreadCountByModule($user);
        $total = $counts['total'] ?? 0;
        unset($counts['total']);

        return response()->json([
            'data' => [
                'total' => $total,
                'by_type' => $counts,
            ],
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

        $saved = UserSetting::where('user_id', $user->id)
            ->value('notification_preferences') ?? [];

        return response()->json(['data' => array_replace_recursive($this->defaultPreferences(), $saved)]);
    }

    /**
     * Update notification settings/preferences.
     *
     * PUT /api/notifications/settings
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'global_mute' => 'sometimes|boolean',
            'weekly_digest' => 'sometimes|boolean',
            'quiet_hours' => 'sometimes|array',
            'quiet_hours.enabled' => 'required_with:quiet_hours|boolean',
            'quiet_hours.start' => 'required_with:quiet_hours|date_format:H:i',
            'quiet_hours.end' => 'required_with:quiet_hours|date_format:H:i',
        ]);

        $user = Auth::user();

        $existing = UserSetting::where('user_id', $user->id)
            ->value('notification_preferences') ?? [];

        $merged = array_replace_recursive($existing, $request->except(['_token', '_method']));

        UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'notification_preferences' => $merged,
                'push_notifications' => ! ($merged['global_mute'] ?? false),
                'email_notifications' => ! ($merged['global_mute'] ?? false),
            ]
        );

        return response()->json(['data' => $merged, 'message' => 'Notification preferences updated.']);
    }

    private function defaultPreferences(): array
    {
        $channelDefaults = fn (bool $email, bool $push = true) => [
            'email' => $email, 'push' => $push, 'in_app' => true,
        ];

        return [
            'new_releases' => $channelDefaults(true),
            'artist_release' => $channelDefaults(true),
            'new_follower' => $channelDefaults(false),
            'likes' => $channelDefaults(false),
            'comments' => $channelDefaults(true),
            'comment_reply' => $channelDefaults(true),
            'playlist_activity' => $channelDefaults(false, false),
            'playlist_share' => $channelDefaults(false),
            'payments' => $channelDefaults(true),
            'payment_received' => $channelDefaults(true),
            'tip_received' => $channelDefaults(false),
            'payout_approved' => $channelDefaults(true),
            'song_approved' => $channelDefaults(true),
            'subscription_expiring' => $channelDefaults(true),
            'award_nomination' => $channelDefaults(true),
            'new_episode' => $channelDefaults(false),
            'new_podcast' => $channelDefaults(false, false),
            'referral_reward' => $channelDefaults(false),
            'ticket_purchase' => $channelDefaults(true),
            'event_reminder' => $channelDefaults(true),
            'system_announcement' => $channelDefaults(false, false),
            'weekly_digest' => true,
            'quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
            'global_mute' => false,
        ];
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
     * Get delivery health diagnostics for notifications.
     *
     * GET /api/notifications/health
     */
    public function health()
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $mailMailer = (string) config('mail.default', 'log');
        $mailHost = (string) config('mail.mailers.smtp.host', '');
        $mailPort = config('mail.mailers.smtp.port');
        $fromAddress = (string) config('mail.from.address', '');

        $jobsCount = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failedJobsCount = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $activeDeviceTokens = Schema::hasTable('device_tokens')
            ? DB::table('device_tokens')->where('is_active', true)->count()
            : null;

        $recentNotifications = Notification::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('type, COUNT(*) as aggregate_count')
            ->groupBy('type')
            ->orderByDesc('aggregate_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'count' => (int) $row->aggregate_count,
            ])
            ->values();

        $failedJobs = collect();
        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')
                ->select(['id', 'queue', 'failed_at', 'exception'])
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get()
                ->map(function ($job) {
                    $summary = str($job->exception ?? '')
                        ->replace("\r", ' ')
                        ->replace("\n", ' ')
                        ->limit(240)
                        ->value();

                    return [
                        'id' => $job->id,
                        'queue' => $job->queue,
                        'failed_at' => $job->failed_at,
                        'exception_summary' => $summary,
                    ];
                })
                ->values();
        }

        return response()->json([
            'data' => [
                'mail' => [
                    'mailer' => $mailMailer,
                    'from_address_configured' => $fromAddress !== '',
                    'smtp_host_configured' => $mailHost !== '',
                    'smtp_port_configured' => ! empty($mailPort),
                    'is_log_mailer' => $mailMailer === 'log',
                    'is_array_mailer' => $mailMailer === 'array',
                ],
                'queue' => [
                    'connection' => $queueConnection,
                    'is_async' => $queueConnection !== 'sync',
                    'pending_jobs' => $jobsCount,
                    'failed_jobs' => $failedJobsCount,
                    'recent_failures' => $failedJobs,
                ],
                'push' => [
                    'active_device_tokens' => $activeDeviceTokens,
                ],
                'notifications' => [
                    'sent_last_24h' => Notification::query()
                        ->where('created_at', '>=', now()->subDay())
                        ->count(),
                    'unread_total' => Notification::query()
                        ->where('is_read', false)
                        ->count(),
                    'top_types_last_7d' => $recentNotifications,
                ],
                'checks' => [
                    'mail_ready' => $mailMailer !== '' && $fromAddress !== '' && ($mailMailer !== 'smtp' || ($mailHost !== '' && ! empty($mailPort))),
                    'queue_ready' => $queueConnection !== '',
                    'push_ready' => ($activeDeviceTokens ?? 0) > 0,
                ],
            ],
        ]);
    }

    /**
     * Broadcast a system announcement to all or a filtered segment of users (admin only).
     *
     * POST /api/notifications/broadcast
     */
    public function broadcast(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'action_url' => 'nullable|url|max:500',
            'action_text' => 'nullable|string|max:100',
            'segment' => 'sometimes|string|in:all,premium,artists,free',
        ]);

        $query = User::query()->where('is_active', true);

        match ($request->input('segment', 'all')) {
            'premium' => $query->whereHas('activeSubscription'),
            'artists' => $query->whereHas('artist'),
            'free' => $query->whereDoesntHave('activeSubscription'),
            default => null,
        };

        $users = $query->get();

        $this->notificationService->sendToUsers(
            $users,
            'system',
            'system_announcement',
            $request->input('title'),
            $request->input('message'),
            ['sent_by' => Auth::id(), 'segment' => $request->input('segment', 'all')],
            $request->input('action_url'),
            $request->input('action_text'),
        );

        return response()->json([
            'message' => 'Broadcast queued successfully.',
            'data' => ['recipient_count' => $users->count()],
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
