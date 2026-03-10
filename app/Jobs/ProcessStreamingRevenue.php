<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\RoyaltySplit;
use App\Models\Song;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // Calculate revenue per stream based on user type
            $ratePerStream = $this->isPremiumUser ? 15.0 : 5.0; // UGX per stream

            $platformFeeRate = ($artist->commission_rate ?? 15) / 100;
            $platformFee = round($ratePerStream * $platformFeeRate, 2);
            $netAmount = round($ratePerStream - $platformFee, 2);

            DB::transaction(function () use ($song, $artist, $ratePerStream, $platformFee, $netAmount) {
                // Create the revenue record
                $revenue = ArtistRevenue::create([
                    'artist_id' => $artist->id,
                    'revenue_type' => ArtistRevenue::TYPE_STREAM,
                    'sourceable_type' => Song::class,
                    'sourceable_id' => $song->id,
                    'song_id' => $song->id,
                    'amount_ugx' => $ratePerStream,
                    'amount_usd' => round($ratePerStream / 3700, 6),
                    'platform_fee' => $platformFee,
                    'net_amount' => $netAmount,
                    'revenue_date' => now()->toDateString(),
                    'status' => ArtistRevenue::STATUS_CONFIRMED,
                ]);

                // Add net earnings to artist balance
                $artist->increment('earnings_balance', $netAmount);

                // Process royalty splits if any exist for this song
                $this->processRoyaltySplits($song, $netAmount);

                Log::info('Streaming revenue processed', [
                    'revenue_id' => $revenue->id,
                    'song_id' => $song->id,
                    'artist_id' => $artist->id,
                    'net_amount' => $netAmount,
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

    private function processRoyaltySplits(Song $song, float $netAmount): void
    {
        $splits = RoyaltySplit::where('song_id', $song->id)
            ->where('applies_to_streaming', true)
            ->where('is_verified', true)
            ->get();

        if ($splits->isEmpty()) {
            return;
        }

        foreach ($splits as $split) {
            $splitPercentage = ($split->split_percentage ?? $split->percentage ?? 0) / 100;
            if ($splitPercentage <= 0) {
                continue;
            }

            $splitAmount = round($netAmount * $splitPercentage, 2);

            ArtistRevenue::create([
                'artist_id' => $split->recipient_id ?? $split->user_id,
                'revenue_type' => ArtistRevenue::TYPE_STREAM,
                'sourceable_type' => Song::class,
                'sourceable_id' => $song->id,
                'song_id' => $song->id,
                'amount_ugx' => $splitAmount,
                'amount_usd' => round($splitAmount / 3700, 6),
                'platform_fee' => 0,
                'net_amount' => $splitAmount,
                'revenue_date' => now()->toDateString(),
                'status' => ArtistRevenue::STATUS_CONFIRMED,
            ]);

            // If the recipient is an artist, credit their balance
            $recipientArtist = Artist::where('user_id', $split->recipient_id ?? $split->user_id)->first();
            if ($recipientArtist) {
                $recipientArtist->increment('earnings_balance', $splitAmount);
            }
        }
    }
}
