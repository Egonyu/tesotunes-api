<?php

namespace Tests\Feature;

use App\Channels\AppNotificationChannel;
use App\Models\Podcast;
use App\Models\SaccoLoan;
use App\Models\Song;
use App\Models\User;
use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Notifications\MemberApprovedNotification;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Notifications\AdminSongPendingNotification;
use App\Notifications\CrossModuleNotification;
use App\Notifications\LoanStatusNotification;
use App\Notifications\PodcastStatusNotification;
use App\Notifications\Store\MonthlyReportNotification;
use App\Notifications\Store\OrderStatusNotification;
use App\Notifications\Store\RefundNotification;
use App\Notifications\Store\StorePaymentNotification;
use App\Notifications\SubscriptionNotification;
use App\Notifications\WeeklyDigestNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_classes_use_app_notification_channel_instead_of_laravel_database_channel(): void
    {
        $user = User::factory()->create();
        $artistUser = User::factory()->create();
        $song = new Song(['title' => 'Contract Song']);
        $song->id = 1;
        $podcast = new Podcast(['title' => 'Contract Podcast', 'slug' => 'contract-podcast']);
        $podcast->id = 1;
        $loan = new SaccoLoan(['amount' => 100000]);
        $loan->id = 1;

        $order = new Order([
            'order_number' => 'ORD-001',
            'subtotal' => 10000,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
        ]);
        $order->id = 1;
        $store = new Store(['name' => 'Contract Store', 'slug' => 'contract-store']);
        $store->id = 1;
        $member = new SaccoMember(['member_number' => 'MEM-001']);
        $member->id = 1;

        $notifications = [
            new StorePaymentNotification('buyer', $order, 10000, 'wallet', 'TXN-1'),
            new RefundNotification($order, 5000, 'Refund reason'),
            new OrderStatusNotification($order, 'confirmed'),
            new MonthlyReportNotification($store, []),
            new AdminSongPendingNotification($song, $artistUser),
            new LoanStatusNotification($loan, 'approved'),
            new PodcastStatusNotification($podcast, 'published'),
            new CrossModuleNotification('music', 'song_approved', 'Song Approved', 'Your song is approved.'),
            new SubscriptionNotification(SubscriptionNotification::SUBSCRIBED, 'Premium'),
            new WeeklyDigestNotification(['songs_listened' => 4, 'minutes_listened' => 16]),
            new WelcomeNotification,
            new MemberApprovedNotification($member),
        ];

        foreach ($notifications as $notification) {
            $channels = $notification->via($user);

            $this->assertContains(AppNotificationChannel::class, $channels);
            $this->assertNotContains('database', $channels);
        }
    }
}
