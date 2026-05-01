<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

class AwardWelcomeCredits
{
    public function __construct(private readonly CreditService $creditService) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            $wallet = $this->creditService->getUserWallet($user);

            $wallet->addCredits(
                (float) config('credits.welcome_bonus', 200),
                'welcome_bonus',
                'Welcome to TesoTunes! Here are 200 credits to get you started.',
                ['event' => 'registration']
            );

            Log::info('Welcome credits awarded', ['user_id' => $user->id]);
        } catch (\Throwable $e) {
            Log::warning('Failed to award welcome credits', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
