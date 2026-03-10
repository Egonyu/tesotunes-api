<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillStreamingRevenue extends Command
{
    protected $signature = 'revenue:backfill
                            {--artist= : Specific artist ID to backfill}
                            {--rate=5 : UGX per stream (default: 5 for free-tier)}
                            {--dry-run : Show what would be created without making changes}';

    protected $description = 'Generate ArtistRevenue records for existing song play_counts that have no revenue records';

    public function handle(): int
    {
        $artistId = $this->option('artist');
        $ratePerStream = (float) $this->option('rate');
        $dryRun = $this->option('dry-run');

        $query = Song::where('play_count', '>', 0)->where('status', 'published');

        if ($artistId) {
            $query->where('artist_id', $artistId);
        }

        $songs = $query->with('artist')->get();

        if ($songs->isEmpty()) {
            $this->info('No songs with plays found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Found {$songs->count()} songs with plays.");

        $totalRevenue = 0;
        $totalRecords = 0;

        foreach ($songs as $song) {
            // Check if streaming revenue already exists for this song
            $existingCount = ArtistRevenue::where('artist_id', $song->artist_id)
                ->where('song_id', $song->id)
                ->where('revenue_type', 'stream')
                ->count();

            if ($existingCount >= $song->play_count) {
                $this->line("  SKIP: {$song->title} — already has {$existingCount} revenue records for {$song->play_count} plays");

                continue;
            }

            $playsToBackfill = $song->play_count - $existingCount;
            $artist = $song->artist;
            $commissionRate = $artist->commission_rate ?? 15;
            $platformFee = ($ratePerStream * $commissionRate / 100) * $playsToBackfill;
            $grossAmount = $ratePerStream * $playsToBackfill;
            $netAmount = $grossAmount - $platformFee;

            $this->line("  {$song->title}: {$playsToBackfill} plays × UGX {$ratePerStream} = UGX {$grossAmount} (fee: {$platformFee}, net: {$netAmount})");

            if (! $dryRun) {
                DB::transaction(function () use ($artist, $song, $grossAmount, $platformFee, $netAmount, $playsToBackfill, $ratePerStream) {
                    ArtistRevenue::create([
                        'artist_id' => $artist->id,
                        'song_id' => $song->id,
                        'sourceable_type' => Song::class,
                        'sourceable_id' => $song->id,
                        'revenue_type' => 'stream',
                        'amount_ugx' => $grossAmount,
                        'amount_usd' => 0,
                        'platform_fee' => $platformFee,
                        'net_amount' => $netAmount,
                        'status' => 'confirmed',
                        'revenue_date' => now(),
                        'notes' => "Backfill: {$playsToBackfill} streams at UGX {$ratePerStream}/play",
                        'transaction_count' => $playsToBackfill,
                    ]);

                    $artist->increment('earnings_balance', $netAmount);
                });
            }

            $totalRevenue += $netAmount;
            $totalRecords++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Summary: {$totalRecords} songs processed, UGX {$totalRevenue} net revenue".($dryRun ? ' (would be)' : '').' generated.');

        if (! $dryRun && $totalRecords > 0) {
            $this->info('Refreshing cached stats...');
            $artistIds = $songs->pluck('artist_id')->unique();
            foreach ($artistIds as $id) {
                Artist::find($id)?->refreshCachedStats();
            }
            $this->info('Done!');
        }

        return self::SUCCESS;
    }
}
