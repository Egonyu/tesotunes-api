<?php

namespace App\Listeners;

use App\Notifications\VerifyEmailNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;

class VerificationEmailTelemetryListener
{
    public function handleSent(NotificationSent $event): void
    {
        if (! $event->notification instanceof VerifyEmailNotification) {
            return;
        }

        Log::channel('audit')->info('auth.email_verification.sent', [
            'user_id' => $event->notifiable->id ?? null,
            'email' => $event->notifiable->email ?? null,
            'channel' => $event->channel,
            'notification' => $event->notification::class,
            'mailer' => config('mail.default'),
            'message_id' => $this->resolveMessageId($event->response),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        if (! $event->notification instanceof VerifyEmailNotification) {
            return;
        }

        Log::channel('security')->warning('auth.email_verification.failed', [
            'user_id' => $event->notifiable->id ?? null,
            'email' => $event->notifiable->email ?? null,
            'channel' => $event->channel,
            'notification' => $event->notification::class,
            'mailer' => config('mail.default'),
            'data' => $event->data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function resolveMessageId(mixed $response): ?string
    {
        if (is_object($response)) {
            if (method_exists($response, 'getMessageId')) {
                return $response->getMessageId();
            }

            if (method_exists($response, 'getOriginalMessage')) {
                $message = $response->getOriginalMessage();

                if (is_object($message) && method_exists($message, 'getMessageId')) {
                    return $message->getMessageId();
                }
            }
        }

        return null;
    }
}
