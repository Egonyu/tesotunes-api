<?php

namespace App\Jobs;

use App\Models\Song;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchSongApprovedWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly int $songId) {}

    public function handle(): void
    {
        $webhookUrl = config('services.n8n.song_approved_webhook');
        if (empty($webhookUrl)) {
            return;
        }

        $song = Song::with(['artist.user', 'primaryGenre'])->find($this->songId);
        if (! $song) {
            return;
        }

        $artistUser = $song->artist?->user;

        Http::timeout(10)->post($webhookUrl, [
            'song' => [
                'id' => $song->id,
                'title' => $song->title,
                'slug' => $song->slug,
                'artwork_url' => $song->artwork_url,
                'duration_formatted' => $song->duration_formatted,
                'is_explicit' => $song->is_explicit,
                'genre' => ['name' => $song->primaryGenre?->name],
                'artist' => [
                    'id' => $song->artist?->id,
                    'name' => $song->artist?->stage_name,
                    'slug' => $song->artist?->slug,
                    'avatar_url' => $song->artist?->avatar_url,
                ],
            ],
            // Pass artist contact details so n8n can send WhatsApp/email
            'artist_phone' => $artistUser?->phone ?? null,
            'artist_email' => $artistUser?->email ?? null,
        ]);

        Log::info('Song approved webhook dispatched', ['song_id' => $this->songId]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Song approved webhook failed', [
            'song_id' => $this->songId,
            'error' => $e->getMessage(),
        ]);
    }
}
