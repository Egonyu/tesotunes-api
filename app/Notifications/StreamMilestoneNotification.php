<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Models\Song;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class StreamMilestoneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Milestone thresholds to trigger notifications */
    public const MILESTONES = [100, 500, 1_000, 5_000, 10_000, 50_000, 100_000, 500_000, 1_000_000];

    public function __construct(
        protected Song $song,
        protected int $milestone
    ) {}

    /**
     * Check if a play count has hit a milestone threshold.
     */
    public static function isMilestone(int $playCount): ?int
    {
        foreach (self::MILESTONES as $milestone) {
            if ($playCount === $milestone) {
                return $milestone;
            }
        }

        return null;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', ExpoPushChannel::class];

        // Email for major milestones (10K+)
        if ($this->milestone >= 10_000) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $formatted = number_format($this->milestone);

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("Your song hit {$formatted} plays!")
            ->greeting("Congratulations {$notifiable->display_name}!")
            ->line("Your song \"{$this->song->title}\" just reached **{$formatted} plays**!")
            ->line('Your music is reaching more listeners every day.')
            ->action('View Analytics', url('/artist/analytics'))
            ->line('Keep making great music!');
    }

    public function toArray(object $notifiable): array
    {
        $formatted = number_format($this->milestone);

        return [
            'type' => 'stream_milestone',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'milestone' => $this->milestone,
            'title' => 'Stream Milestone',
            'message' => "\"{$this->song->title}\" reached {$formatted} plays!",
            'icon' => 'fire',
            'color' => 'orange',
            'priority' => $this->milestone >= 10_000 ? 'high' : 'normal',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $formatted = number_format($this->milestone);

        return [
            'title' => "🔥 {$formatted} Plays!",
            'body' => "\"{$this->song->title}\" just hit {$formatted} plays!",
            'data' => [
                'type' => 'stream_milestone',
                'songId' => $this->song->id,
                'milestone' => $this->milestone,
                'screen' => 'SongDetail',
                'params' => ['songId' => $this->song->id],
            ],
            'options' => [
                'priority' => 'high',
            ],
        ];
    }
}
