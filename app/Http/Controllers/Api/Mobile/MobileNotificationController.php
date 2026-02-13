<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileNotificationController extends Controller
{
    private PushNotificationService $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Register device token for push notifications.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_token' => 'required|string',
            'platform' => 'required|string|in:ios,android,web',
            'device_info' => 'array',
            'device_info.brand' => 'string',
            'device_info.manufacturer' => 'string',
            'device_info.modelName' => 'string',
            'device_info.osName' => 'string',
            'device_info.osVersion' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $deviceToken = $this->pushService->registerDeviceToken(
                $request->user(),
                $request->device_token,
                $request->platform,
                $request->device_info ?? []
            );

            return response()->json([
                'success' => true,
                'message' => 'Device token registered successfully',
                'data' => [
                    'device_id' => $deviceToken->id,
                    'registered_at' => $deviceToken->created_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register device token',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'likes' => 'boolean',
            'comments' => 'boolean',
            'follows' => 'boolean',
            'musicUpdates' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();

            // Update user notification preferences
            // You might want to add a notification_preferences JSON column to users table
            $preferences = [
                'likes' => $request->boolean('likes', true),
                'comments' => $request->boolean('comments', true),
                'follows' => $request->boolean('follows', true),
                'music_updates' => $request->boolean('musicUpdates', true),
            ];

            $user->update(['notification_preferences' => $preferences]);

            return response()->json([
                'success' => true,
                'message' => 'Notification preferences updated successfully',
                'data' => [
                    'preferences' => $preferences,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update notification preferences',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get user's notification preferences.
     */
    public function getPreferences(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $preferences = $user->notification_preferences ?? [
                'likes' => true,
                'comments' => true,
                'follows' => true,
                'music_updates' => true,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'preferences' => $preferences,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get notification preferences',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Send test notification to user's devices.
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'body' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $success = $this->pushService->sendNotificationToUser(
                $request->user(),
                $request->title,
                $request->body,
                ['type' => 'test']
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No active devices found or notification failed',
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get user's registered devices.
     */
    public function getDevices(Request $request): JsonResponse
    {
        try {
            $devices = $request->user()->deviceTokens()
                ->active()
                ->select(['id', 'platform', 'device_info', 'created_at', 'last_used_at'])
                ->get()
                ->map(function ($device) {
                    return [
                        'id' => $device->id,
                        'platform' => $device->platform,
                        'device_name' => $this->getDeviceName($device->device_info),
                        'registered_at' => $device->created_at,
                        'last_used_at' => $device->last_used_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'devices' => $devices,
                    'total_count' => $devices->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get devices',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove device token.
     */
    public function removeDevice(Request $request, int $deviceId): JsonResponse
    {
        try {
            $device = $request->user()->deviceTokens()->findOrFail($deviceId);
            $device->deactivate();

            return response()->json([
                'success' => true,
                'message' => 'Device removed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove device',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get user-friendly device name.
     */
    private function getDeviceName(array $deviceInfo): string
    {
        if (empty($deviceInfo)) {
            return 'Unknown Device';
        }

        $brand = $deviceInfo['brand'] ?? '';
        $model = $deviceInfo['modelName'] ?? $deviceInfo['model'] ?? '';
        $os = $deviceInfo['osName'] ?? '';

        if ($brand && $model) {
            return "{$brand} {$model}";
        }

        if ($brand) {
            return $brand;
        }

        if ($model) {
            return $model;
        }

        if ($os) {
            return "{$os} Device";
        }

        return 'Unknown Device';
    }
}