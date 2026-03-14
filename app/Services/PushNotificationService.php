<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Send push notification to user's devices.
     */
    public function sendNotificationToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
        array $options = []
    ): bool {
        $tokens = $user->deviceTokens()->active()->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info("No active device tokens found for user {$user->id}");

            return false;
        }

        return $this->sendNotificationToTokens($tokens, $title, $body, $data, $options);
    }

    /**
     * Send push notification to multiple users.
     */
    public function sendNotificationToUsers(
        array $userIds,
        string $title,
        string $body,
        array $data = [],
        array $options = []
    ): bool {
        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->active()
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            Log::info('No active device tokens found for provided users');

            return false;
        }

        return $this->sendNotificationToTokens($tokens, $title, $body, $data, $options);
    }

    /**
     * Send push notification to specific device tokens.
     */
    public function sendNotificationToTokens(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        array $options = []
    ): bool {
        if (empty($tokens)) {
            return false;
        }

        // Split tokens into chunks of 100 (Expo's limit)
        $chunks = array_chunk($tokens, 100);
        $success = true;

        foreach ($chunks as $chunk) {
            if (! $this->sendExpoNotification($chunk, $title, $body, $data, $options)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send notification via Expo Push Notification service.
     */
    private function sendExpoNotification(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        array $options = []
    ): bool {
        try {
            $payload = [
                'to' => $tokens,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => $options['sound'] ?? 'default',
                'priority' => $options['priority'] ?? 'normal',
                'channelId' => $options['channelId'] ?? 'default',
            ];

            // Add iOS specific options
            if (isset($options['badge'])) {
                $payload['badge'] = $options['badge'];
            }

            // Add Android specific options
            if (isset($options['icon'])) {
                $payload['icon'] = $options['icon'];
            }

            if (isset($options['color'])) {
                $payload['color'] = $options['color'];
            }

            $response = HttpClientFactory::make('expo_push')->post(self::EXPO_PUSH_URL, $payload);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['data'])) {
                    $this->handlePushTickets($result['data'], $tokens);
                }

                Log::info('Push notification sent successfully', [
                    'tokens_count' => count($tokens),
                    'title' => $title,
                ]);

                return true;
            } else {
                Log::error('Failed to send push notification', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception sending push notification', [
                'error' => $e->getMessage(),
                'tokens_count' => count($tokens),
            ]);

            return false;
        }
    }

    /**
     * Handle push notification tickets.
     */
    private function handlePushTickets(array $tickets, array $tokens): void
    {
        foreach ($tickets as $index => $ticket) {
            $token = $tokens[$index] ?? null;

            if (! $token) {
                continue;
            }

            if ($ticket['status'] === 'error') {
                $error = $ticket['details']['error'] ?? 'unknown';

                Log::warning('Push notification failed for token', [
                    'token' => substr($token, 0, 20).'...',
                    'error' => $error,
                ]);

                // Deactivate invalid tokens
                if (in_array($error, ['DeviceNotRegistered', 'InvalidCredentials'])) {
                    DeviceToken::where('token', $token)->update(['is_active' => false]);
                }
            }
        }
    }

    /**
     * Register device token for user.
     */
    public function registerDeviceToken(
        User $user,
        string $token,
        string $platform,
        array $deviceInfo = []
    ): DeviceToken {
        // Deactivate existing tokens for this device
        DeviceToken::where('user_id', $user->id)
            ->where('token', $token)
            ->update(['is_active' => false]);

        // Create new token record
        return DeviceToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => $platform,
            'device_type' => $deviceInfo['model'] ?? $deviceInfo['modelName'] ?? $deviceInfo['device_type'] ?? null,
            'device_name' => $deviceInfo['name'] ?? $deviceInfo['brand'] ?? $deviceInfo['device_name'] ?? null,
            'app_version' => $deviceInfo['app_version'] ?? null,
            'is_active' => true,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Send notification for post like.
     */
    public function sendLikeNotification(User $postOwner, User $liker, $post): bool
    {
        $title = 'New Like';
        $body = "{$liker->name} liked your post";

        $data = [
            'type' => 'like',
            'postId' => $post->id,
            'userId' => $liker->id,
            'userName' => $liker->name,
        ];

        return $this->sendNotificationToUser($postOwner, $title, $body, $data);
    }

    /**
     * Send notification for new comment.
     */
    public function sendCommentNotification(User $postOwner, User $commenter, $post, string $comment): bool
    {
        $title = 'New Comment';
        $body = "{$commenter->name} commented on your post";

        $data = [
            'type' => 'comment',
            'postId' => $post->id,
            'userId' => $commenter->id,
            'userName' => $commenter->name,
            'comment' => substr($comment, 0, 100), // Truncate for notification
        ];

        return $this->sendNotificationToUser($postOwner, $title, $body, $data);
    }

    /**
     * Send notification for new follower.
     */
    public function sendFollowNotification(User $followedUser, User $follower): bool
    {
        $title = 'New Follower';
        $body = "{$follower->name} started following you";

        $data = [
            'type' => 'follow',
            'userId' => $follower->id,
            'userName' => $follower->name,
        ];

        return $this->sendNotificationToUser($followedUser, $title, $body, $data);
    }

    /**
     * Send notification for new music from followed artist.
     */
    public function sendMusicUpdateNotification(array $followerIds, User $artist, $song): bool
    {
        $title = 'New Music';
        $body = "{$artist->name} released a new song: {$song->title}";

        $data = [
            'type' => 'music_update',
            'songId' => $song->id,
            'artistId' => $artist->id,
            'artistName' => $artist->name,
            'songTitle' => $song->title,
        ];

        return $this->sendNotificationToUsers($followerIds, $title, $body, $data);
    }

    /**
     * Clean up inactive device tokens.
     */
    public function cleanupInactiveTokens(): int
    {
        // Remove tokens that haven't been used in 30 days
        $cutoffDate = now()->subDays(30);

        return DeviceToken::where('last_used_at', '<', $cutoffDate)
            ->orWhere('is_active', false)
            ->delete();
    }
}
