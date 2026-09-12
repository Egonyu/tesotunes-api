<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsFrontendUrls;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * Custom branded email verification notification for TesoTunes.
 * Extends Laravel's built-in VerifyEmail notification.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable;

    /**
     * How long the link stays valid, in minutes.
     *
     * Both the signature and the sentence in the email body read this, so the
     * promise made to the reader cannot drift from what the link actually does.
     */
    private function expiryMinutes(): int
    {
        return max(1, (int) config('auth.verification.expire', 60));
    }

    /**
     * The same window, phrased for the reader: "12 hours", not "720 minutes".
     */
    private function expiryWindowForHumans(): string
    {
        $minutes = $this->expiryMinutes();

        if ($minutes < 60 || $minutes % 60 !== 0) {
            return $minutes.' '.str('minute')->plural($minutes);
        }

        $hours = intdiv($minutes, 60);

        return $hours.' '.str('hour')->plural($hours);
    }

    protected function verificationUrl($notifiable): string
    {
        $signedBackendUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($this->expiryMinutes()),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        $path = (string) parse_url($signedBackendUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $id = $segments[2] ?? null;
        $hash = $segments[3] ?? null;
        $queryParams = [];
        parse_str((string) parse_url($signedBackendUrl, PHP_URL_QUERY), $queryParams);

        if ($id !== null) {
            $queryParams['id'] = $id;
        }

        if ($hash !== null) {
            $queryParams['hash'] = $hash;
        }

        $query = http_build_query($queryParams);

        return $this->frontendUrl('/verify-email'.($query ? "?{$query}" : ''));
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify Your Email — TesoTunes')
            ->greeting('Welcome to TesoTunes! 🎵')
            ->line('Thank you for joining the premier African music distribution platform.')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', $url)
            ->line('This verification link will expire in '.$this->expiryWindowForHumans().'.')
            ->line('If you did not create an account, no further action is required.');
    }
}
