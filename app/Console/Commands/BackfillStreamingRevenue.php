<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Song;
use App\Services\Revenue\StreamingRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillStreamingRevenue extends Command
{
    protected $signature = 'revenue:backfill
                            {--artist= : Specific artist ID to backfill}
                            {--rate= : UGX per stream override}
                            {--dry-run : Show what would be created without making changes}';

    protected $description = 'Generate ArtistRevenue records for existing song play_counts that have no revenue records';

    public function handle(): int
    {
        $artistId = $this->option('artist');
        $rateOverride = $this->option('rate');
        $dryRun = $this->option('dry-run');
        $rateService = app(StreamingRateService::class);
        $defaultPayout = $rateService->calculateStreamPayout();

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
            $existingCount = ArtistRevenue::where('artist_id', $song->artist_id)
                ->where('sourceable_type', Song::class)
                ->where('sourceable_id', $song->id)
                ->where('revenue_type', ArtistRevenue::TYPE_STREAM)
                ->count();

            if ($existingCount >= $song->play_count) {
                $this->line("  SKIP: {$song->title} - already has {$existingCount} revenue records for {$song->play_count} plays");

                continue;
            }

            $playsToBackfill = $song->play_count - $existingCount;
            $artist = $song->artist;
            $ratePerStream = $rateOverride !== $null ? round((float) $rateOverride, 2) : $defaultPayout['rate_per_stream'];
            $commissionPercent = $defaultPayout['commission_percent'];
            $platformFeePerStream = round($ratePerStream * ($commissionPercent / 100), 2);
            $grossAmount = round($ratePerStream * $playsToBackfill, 2);
            $platformFee = round($platformFeePerStream * $playsToBackfill, 2);
            $netAmount = round($grossAmount - $platformFee, 2);
            $auditNote = $rateService->encodeAuditPayload($rateService->buildStreamAuditPayload(
                context: [
                    'audit_type' => 'stream_backfill',
                    'song_id' => $song->id,
                    'artist_id' => $artist->id,
                    'backfill_play_count' => $playsToBackfill,
                    'gross_amount_ugx' => number_format($grossAmount, 2, '.', ''),
                    'platform_fee_ugx' => number_format($platformFee, 2, '.', ''),
                    'net_amount_ugx' => number_format($netAmount, 2, '.', ''),
                    'rate_override_ugx' => $rateOverride !== $null ? number_format((float) $rateOverride, 2, '.', '') : null,
                ]
            ));

            $this->line("  {$song->title}: {$playsToBackfill} plays x UGX {$ratePerStream} = UGX {$grossAmount} (fee: {$platformFee}, net: {$netAmount})");

            if (! $dryRun) {
                DB::transaction(function () use ($artist, $song, $grossAmount, $platformFee, $netAmount, $auditNote) {
                    ArtistRevenue::create([
                        'uuid' => (string) Str::uuid(),
                        'artist_id' => $artist->id,
                        'sourceable_type' => Song::class,
                        'sourceable_id' => $song->id,
                        'revenue_type' => ArtistRevenue::TYPE_STREAM,
                        'amount_ugx' => $grossAmount,
                        'amount_usd' => 0,
                        'platform_fee' => $platformFee,
                        'net_amount' => $netAmount,
                        'status' => ArtistRevenue::STATUS_CONFIRMED,
                        'revenue_date' => now(),
                        'notes' => $auditNote,
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
