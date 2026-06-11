<?php

namespace Tests\Feature\Commerce;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Commerce\Settlement;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class MusicRevenueSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private function createRevenue(Artist $artist, Song $song, string $status, float $gross = 1000, float $net = 700): ArtistRevenue
    {
        return ArtistRevenue::create([
            'uuid' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'revenue_type' => ArtistRevenue::TYPE_STREAM,
            'sourceable_type' => Song::class,
            'sourceable_id' => $song->id,
            'amount_ugx' => $gross,
            'platform_fee' => $gross - $net,
            'net_amount' => $net,
            'revenue_date' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_confirmed_revenue_mirrors_into_the_ledger_as_cleared(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $owner->id]);
        $song = Song::factory()->create(['artist_id' => $artist->id]);

        $revenue = $this->createRevenue($artist, $song, ArtistRevenue::STATUS_CONFIRMED, 1000, 700);

        $settlement = Settlement::query()
            ->where('source_type', $revenue->getMorphClass())
            ->where('source_id', $revenue->id)
            ->first();

        $this->assertNotNull($settlement);
        $this->assertSame(Settlement::STATUS_CLEARED, $settlement->status);
        $this->assertSame(Settlement::VERTICAL_MUSIC, $settlement->vertical);
        $this->assertSame(ArtistRevenue::TYPE_STREAM, $settlement->kind);
        $this->assertSame('700.00', (string) $settlement->net_ugx, 'ledger net must equal ArtistRevenue net_amount');
        $this->assertEquals($owner->id, $settlement->beneficiary_user_id);
    }

    public function test_pending_revenue_stays_pending_until_confirmation(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $owner->id]);
        $song = Song::factory()->create(['artist_id' => $artist->id]);

        $revenue = $this->createRevenue($artist, $song, ArtistRevenue::STATUS_PENDING);

        $settlement = Settlement::query()
            ->where('source_type', $revenue->getMorphClass())
            ->where('source_id', $revenue->id)
            ->first();

        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);

        // The hourly time-based clearance must NOT touch confirmation-gated rows.
        app(\App\Services\Commerce\SettlementService::class)->clearDue();
        $this->assertSame(Settlement::STATUS_PENDING, $settlement->fresh()->status);

        $revenue->update(['status' => ArtistRevenue::STATUS_CONFIRMED]);
        $this->assertSame(Settlement::STATUS_CLEARED, $settlement->fresh()->status);
    }

    public function test_backfill_command_mirrors_historical_rows_idempotently(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $owner->id]);
        $song = Song::factory()->create(['artist_id' => $artist->id]);

        $revenue = $this->createRevenue($artist, $song, ArtistRevenue::STATUS_CONFIRMED);
        // Simulate a pre-ledger historical row by deleting the mirrored settlement.
        Settlement::query()->where('source_id', $revenue->id)->where('source_type', $revenue->getMorphClass())->delete();

        Artisan::call('commerce:backfill-music-settlements');
        Artisan::call('commerce:backfill-music-settlements');

        $count = Settlement::query()
            ->where('source_type', $revenue->getMorphClass())
            ->where('source_id', $revenue->id)
            ->count();

        $this->assertSame(1, $count, 'backfill must be idempotent');
    }
}
