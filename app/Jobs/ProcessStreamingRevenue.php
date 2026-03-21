<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\RoyaltySplit;
use App\Models\Song;
use App\Services\Revenue\StreamingRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessStreamingRevenue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        protected int $songId,
        protected int $userId,
        protected int $artistId,
        protected bool $isPremiumUser = false,
        protected ?string $country = null,
    ) {}

    public function handle(): void
    {
        try {
            $song = Song::find($this->songId);
            $artist = Artist::find($this->artistId);

            if (! $song || ! $artist) {
                Log::warning('ProcessStreamingRevenue: song or artist not found', [
                    'song_id' => $this->songId,
                    'artist_id' => $this->artistId,
                ]);

                return;
            }

            $rateService = app(StreamingRateService::class);
            $payout = $rateService->calculateStreamPayout(
                userId: $this->userId,
                fallbackPremium: $this->isPremiumUser
            );
            $auditPayload = $rateService->buildStreamAuditPayload(
                userId: $this->userId,
                fallbackPremium: $this->isPremiumUser,
                context: [
                    'song_id' => $song->id,
                    'artist_id' => $artist->id,
                    'country' => $this->country,
                    'gross_amount_ugx' => number_format($payout['rate_per_stream'], 2, '.', ''),
                    'platform_fee_ugx' => number_format($payout['platform_fee'], 2, '.', ''),
                    'net_amount_ugx' => number_format($payout['net_amount'], 2, '.', ''),
                ]
            );
            $auditNote = $rateService->encodeAuditPayload($auditPayload);

            DB::transaction(function () use ($song, $artist, $payout, $auditNote, $auditPayload) {
                $revenue = ArtistRevenue::create([
                    'uuid' => (string) Str::uuid(),
                    'artist_id' => $artist->id,
                    'revenue_type' => ArtistRevenue::TYPE_STREAM,
                    'sourceable_type' => Song::class,
                    'sourceable_id' => $song->id,
                    'amount_ugx' => $payout['rate_per_stream'],
                    'amount_usd' => round($payout['rate_per_stream'] / 3700, 6),
                    'platform_fee' => $payout['platform_fee'],
                    'net_amount' => $payout['net_amount'],
                    'revenue_date' => now()->toDateString(),
                    'status' => ArtistRevenue::STATUS_CONFIRMED,
                    'notes' => $auditNote,
                ]);

                $artist->increment('earnings_balance', $payout['net_amount']);

                $this->processRoyaltySplits($song, $payout['net_amount'], $auditPayload);

                Log::info('Streaming revenue processed', [
                    'revenue_id' => $revenue->id,
                    'song_id' => $song->id,
                    'artist_id' => $artist->id,
                    'rate_per_stream' => $payout['rate_per_stream'],
                    'platform_fee' => $payout['platform_fee'],
                    'net_amount' => $payout['net_amount'],
                    'rate_source' => $auditPayload['rate_source'] ?? null,
                    'listener_plan_slug' => $auditPayload['listener_plan_slug'] ?? null,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('ProcessStreamingRevenue failed', [
                'song_id' => $this->songId,
                'artist_id' => $this->artistId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function processRoyaltySplits(Song $song, float $netAmount, array $auditPayload): void
    {
        $splits = RoyaltySplit::where('song_id', $song->id)
            ->where('applies_to_streaming', true)
            ->where('is_verified', true)
            ->get();

        if ($splits->isEmpty()) {
            return;
        }

        $rateService = app(StreamingRateService::class);

        foreach ($splits as $split) {
            $splitPercentage = ($split->split_percentage ?? $split->percentage ?? 0) / 100;
            if ($splitPercentage <= 0) {
                continue;
            }

            $splitAmount = round($netAmount * $splitPercentage, 2);
            $splitAuditNote = $rateService->encodeAuditPayload(array_merge($auditPayload, [
                'audit_type' => 'stream_split_payout',
                'split_percentage' => number_format($splitPercentage * 100, 2, '.', ''),
                'split_amount_ugx' => number_format($splitAmount, 2, '.', ''),
                'recipient_user_id' => $split->recipient_id ?? $split->user_id,
            ]));

            ArtistRevenue::create([
                'uuid' => (string) Str::uuid(),
                'artist_id' => $split->recipient_id ?? $split->user_id,
                'revenue_type' => ArtistRevenue::TYPE_STREAM,
                'sourceable_type' => Song::class,
                'sourceable_id' => $song->id,
                'amount_ugx' => $splitAmount,
                'amount_usd' => round($splitAmount / 3700, 6),
                'platform_fee' => 0,
                'net_amount' => $splitAmount,
                'revenue_date' => now()->toDateString(),
                'status' => ArtistRevenue::STATUS_CONFIRMED,
                'notes' => $splitAuditNote,
            ]);

            $recipientArtist = Artist::where('user_id', $split->recipient_id ?? $split->user_id)->first();
            if ($recipientArtist) {
                $recipientArtist->increment('earnings_balance', $splitAmount);
            }
        }
    }
}
