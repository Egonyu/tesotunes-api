<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchArtistUpgradedWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly int $userId) {}

    public function handle(): void
    {
        $webhookUrl = config('services.n8n.artist_upgraded_webhook');
        if (empty($webhookUrl)) {
            return;
        }

        $user = User::with('artist')->find($this->userId);
        if (! $user) {
            return;
        }

        Http::timeout(10)->post($webhookUrl, [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'stage_name' => $user->artist?->stage_name,
                'slug' => $user->artist?->slug,
            ],
        ]);

        Log::info('Artist upgraded webhook dispatched', ['user_id' => $this->userId]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Artist upgraded webhook failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
