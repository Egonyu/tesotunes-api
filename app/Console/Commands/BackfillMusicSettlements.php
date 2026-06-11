<?php

namespace App\Console\Commands;

use App\Models\ArtistRevenue;
use App\Observers\ArtistRevenueObserver;
use Illuminate\Console\Command;

/**
 * Mirrors historical ArtistRevenue rows into the unified settlement ledger.
 * Idempotent: the ledger's (source, beneficiary, kind) key makes re-runs
 * skip already-mirrored rows, so this is safe to run repeatedly.
 */
class BackfillMusicSettlements extends Command
{
    protected $signature = 'commerce:backfill-music-settlements {--chunk=200 : Rows per chunk}';

    protected $description = 'Mirror historical ArtistRevenue rows into the settlement ledger';

    public function handle(ArtistRevenueObserver $observer): int
    {
        $mirrored = 0;
        $skipped = 0;

        ArtistRevenue::query()
            ->with('artist.user')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($revenues) use ($observer, &$mirrored, &$skipped) {
                foreach ($revenues as $revenue) {
                    $settlement = $observer->mirrorToSettlementLedger($revenue);
                    $settlement ? $mirrored++ : $skipped++;
                }
            });

        $this->info("Mirrored {$mirrored} revenue row(s) into the ledger ({$skipped} skipped).");

        return self::SUCCESS;
    }
}
