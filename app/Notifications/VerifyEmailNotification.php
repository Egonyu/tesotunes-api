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

    protected function verificationUrl($notifiable): string
    {
        $signedBackendUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
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
            ->line('This verification link will expire in 60 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
