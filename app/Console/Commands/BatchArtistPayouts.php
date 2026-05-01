<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BatchArtistPayouts extends Command
{
    protected $signature = 'artists:batch-payout
                            {--dry-run : List eligible artists without creating payout records}
                            {--min-balance=50000 : Minimum UGX earnings balance to qualify}';

    protected $description = 'Aggregate confirmed artist revenues and queue payout records for eligible artists';

    public function handle(): int
    {
        $minBalance = (float) $this->option('min-balance');
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? '[DRY RUN] Scanning eligible artists…' : 'Processing artist batch payouts…');

        // Find artists with confirmed revenue totalling >= minBalance
        $eligible = ArtistRevenue::query()
            ->select('artist_id', DB::raw('SUM(net_amount) as total_net'))
            ->where('status', ArtistRevenue::STATUS_CONFIRMED)
            ->where('revenue_date', '<=', now()->subDays(7))
            ->groupBy('artist_id')
            ->having('total_net', '>=', $minBalance)
            ->with('artist.user')
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No artists qualify for payout this cycle.');

            return self::SUCCESS;
        }

        $this->table(
            ['Artist ID', 'Artist Name', 'Total Net (UGX)', 'Status'],
            $eligible->map(fn ($row) => [
                $row->artist_id,
                $row->artist?->stage_name ?? 'Unknown',
                number_format((float) $row->total_net),
                $dryRun ? 'would queue' : 'queuing…',
            ])
        );

        if ($dryRun) {
            $this->info('Dry run complete. No records created.');

            return self::SUCCESS;
        }

        $queued = 0;
        $failed = 0;

        foreach ($eligible as $row) {
            $artist = $row->artist;

            if (! $artist || ! $artist->user) {
                $this->warn("Skipping artist_id={$row->artist_id}: no linked user.");
                $failed++;

                continue;
            }

            try {
                DB::transaction(function () use ($artist, $row) {
                    // Create a pending payout payment record for admin to approve
                    $payment = new Payment([
                        'user_id' => $artist->user_id,
                        'payable_type' => Artist::class,
                        'payable_id' => $artist->id,
                        'payment_type' => 'artist_payout',
                        'payment_method' => 'mobile_money',
                        'provider' => 'zengapay',
                        'currency' => 'UGX',
                        'description' => 'Weekly batch payout for '.$artist->stage_name,
                        'payment_reference' => 'PAYOUT-'.strtoupper(uniqid()),
                        'transaction_reference' => 'PAYOUT-'.strtoupper(uniqid()),
                        'metadata' => [
                            'payout_type' => 'weekly_batch',
                            'revenue_period_end' => now()->subDays(7)->toDateString(),
                            'qualifying_revenue_count' => ArtistRevenue::where('artist_id', $artist->id)
                                ->where('status', ArtistRevenue::STATUS_CONFIRMED)
                                ->where('revenue_date', '<=', now()->subDays(7))
                                ->count(),
                        ],
                    ]);

                    $payment->forceFill([
                        'amount' => (float) $row->total_net,
                        'status' => Payment::STATUS_PENDING,
                    ])->save();

                    // Mark the included revenue records as pending payout
                    ArtistRevenue::where('artist_id', $artist->id)
                        ->where('status', ArtistRevenue::STATUS_CONFIRMED)
                        ->where('revenue_date', '<=', now()->subDays(7))
                        ->update(['status' => ArtistRevenue::STATUS_PAID]);
                });

                $queued++;

                Log::info('Artist payout queued', [
                    'artist_id' => $artist->id,
                    'amount' => $row->total_net,
                ]);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Failed to queue artist payout', [
                    'artist_id' => $artist->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to queue payout for {$artist->stage_name}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Queued: {$queued}, Failed: {$failed}.");
        $this->info('Admin can review and approve pending payouts from the admin panel.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
