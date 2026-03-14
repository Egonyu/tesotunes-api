<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\CrossModuleNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossModuleNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_user_writes_to_custom_notifications_table(): void
    {
        $user = User::factory()->create();

        app(CrossModuleNotificationService::class)->sendToUser(
            $user,
            'music',
            'test',
            'Test Notification',
            'This is a test.'
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'test',
            'category' => 'music',
            'title' => 'Test Notification',
            'message' => 'This is a test.',
        ]);
    }

    public function test_unread_counts_and_mark_read_use_custom_notification_rows(): void
    {
        $user = User::factory()->create();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'song_approved',
            'category' => 'music',
            'title' => 'Song Approved',
            'message' => 'Your song is approved.',
            'is_read' => false,
            'data' => ['module' => 'music'],
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'loan_approved',
            'category' => 'sacco',
            'title' => 'Loan Approved',
            'message' => 'Your loan is approved.',
            'is_read' => false,
            'data' => ['module' => 'sacco'],
        ]);

        $service = app(CrossModuleNotificationService::class);

        $counts = $service->getUnreadCountByModule($user);

        $this->assertSame(1, $counts['music']);
        $this->assertSame(1, $counts['sacco']);
        $this->assertSame(2, $counts['total']);

        $service->markModuleNotificationsAsRead($user, 'music');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'category' => 'music',
            'is_read' => false,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'category' => 'sacco',
            'is_read' => false,
        ]);
    }
}
