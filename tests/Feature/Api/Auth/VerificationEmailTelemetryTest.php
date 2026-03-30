<?php

namespace Tests\Feature\Api\Auth;

use App\Listeners\VerificationEmailTelemetryListener;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VerificationEmailTelemetryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listener_logs_sent_verification_email_events(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('audit')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'auth.email_verification.sent'
                    && $context['email'] !== null
                    && $context['channel'] === 'mail';
            });

        $user = User::factory()->create();
        $listener = new VerificationEmailTelemetryListener;

        $listener->handleSent(new NotificationSent(
            $user,
            new VerifyEmailNotification,
            'mail',
            new class
            {
                public function getMessageId(): string
                {
                    return 'verification-message-id';
                }
            }
        ));

    }

    public function test_listener_logs_failed_verification_email_events(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('security')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'auth.email_verification.failed'
                    && $context['email'] !== null
                    && $context['channel'] === 'mail';
            });

        $user = User::factory()->create();
        $listener = new VerificationEmailTelemetryListener;

        $listener->handleFailed(new NotificationFailed(
            $user,
            new VerifyEmailNotification,
            'mail',
            ['exception' => 'smtp rejected recipient']
        ));

    }
}
