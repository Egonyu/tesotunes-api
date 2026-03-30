<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigestNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected array $digest
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            ['mail', AppNotificationChannel::class],
            'music'
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $displayName = trim((string) ($notifiable->display_name ?? $notifiable->name ?? 'there'));
        if ($displayName === '') {
            $displayName = 'there';
        }

        $mail = (new MailMessage)
            ->subject('Your Weekly Music Recap — TesoTunes')
            ->greeting("Hey {$displayName}!");

        $songsListened = $this->digest['songs_listened'] ?? 0;
        $minutesListened = $this->digest['minutes_listened'] ?? 0;
        $topArtist = $this->digest['top_artist'] ?? null;
        $topSong = $this->digest['top_song'] ?? null;
        $newFollowers = $this->digest['new_followers'] ?? 0;
        $creditsEarned = $this->digest['credits_earned'] ?? 0;

        $mail->line("Here's your weekly recap:");

        if ($songsListened > 0) {
            $mail->line("**{$songsListened} songs** listened ({$minutesListened} minutes)");
        }

        if ($topArtist) {
            $mail->line("**Top Artist**: {$topArtist}");
        }

        if ($topSong) {
            $mail->line("**Most Played**: {$topSong}");
        }

        if ($newFollowers > 0) {
            $mail->line("**{$newFollowers} new follower(s)** this week");
        }

        if ($creditsEarned > 0) {
            $mail->line("**{$creditsEarned} credits** earned");
        }

        return $mail
            ->action('Discover New Music', $this->frontendUrl('/discover'))
            ->line('Keep the music playing!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'weekly_digest',
            'module' => 'music',
            'digest' => $this->digest,
            'title' => 'Your Weekly Recap',
            'message' => $this->buildSummaryMessage(),
            'icon' => 'chart-bar',
            'color' => 'blue',
        ];
    }

    private function buildSummaryMessage(): string
    {
        $songs = $this->digest['songs_listened'] ?? 0;
        $minutes = $this->digest['minutes_listened'] ?? 0;

        if ($songs > 0) {
            return "You listened to {$songs} songs ({$minutes} min) this week.";
        }

        return 'Check out what\'s trending this week on TesoTunes!';
    }
}
