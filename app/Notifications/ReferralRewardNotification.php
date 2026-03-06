<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReferralRewardNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected string $referredUserName,
        protected float $creditsEarned,
        protected float $newBalance = 0
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            ['database', ExpoPushChannel::class],
            'credits'
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'referral_reward',
            'module' => 'credits',
            'referred_user' => $this->referredUserName,
            'credits_earned' => $this->creditsEarned,
            'new_balance' => $this->newBalance,
            'title' => 'Referral Reward',
            'message' => "You earned {$this->creditsEarned} credits! {$this->referredUserName} joined using your referral.",
            'icon' => 'gift',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Referral Reward!',
            'body' => "+{$this->creditsEarned} credits — {$this->referredUserName} joined via your link!",
            'data' => [
                'type' => 'referral_reward',
                'creditsEarned' => $this->creditsEarned,
                'newBalance' => $this->newBalance,
                'screen' => 'Wallet',
            ],
        ];
    }
}
