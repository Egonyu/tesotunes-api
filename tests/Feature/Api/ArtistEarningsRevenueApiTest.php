<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\ArtistRevenue;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Song;
use App\Models\User;
use App\Services\Revenue\StreamingRateService;
use Illuminate\Support\Str;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class ArtistEarningsRevenueApiTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $artistUser;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->verified()->create([
            'user_id' => $this->artistUser->id,
            'earnings_balance' => 150,
        ]);
    }

    public function test_artist_earnings_uses_recorded_stream_revenue_not_play_count_multiplier(): void
    {
        $song = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'user_id' => $this->artistUser->id,
            'status' => 'published',
            'play_count' => 999,
            'download_count' => 3,
        ]);

        Setting::set('platform_commissions', [
            'streaming_percent' => 20,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $streamAudit = app(StreamingRateService::class)->encodeAuditPayload([
            'audit_type' => 'stream_payout',
            'listener_plan_slug' => 'gold-monthly',
            'listener_plan_name' => 'Gold Monthly',
            'listener_plan_tier' => 'premium',
            'rate_source' => 'plan_metadata',
            'effective_stream_rate_ugx' => '200.00',
            'streaming_commission_percent' => '20.00',
            'gross_amount_ugx' => '200.00',
            'platform_fee_ugx' => '50.00',
            'net_amount_ugx' => '150.00',
            'song_id' => $song->id,
            'artist_id' => $this->artist->id,
        ]);

        ArtistRevenue::create([
            'uuid' => (string) Str::uuid(),
            'artist_id' => $this->artist->id,
            'revenue_type' => ArtistRevenue::TYPE_STREAM,
            'sourceable_type' => Song::class,
            'sourceable_id' => $song->id,
            'amount_ugx' => 200,
            'amount_usd' => 0.054054,
            'platform_fee' => 50,
            'net_amount' => 150,
            'revenue_date' => now()->toDateString(),
            'status' => ArtistRevenue::STATUS_CONFIRMED,
            'notes' => $streamAudit,
        ]);

        Payment::factory()->completed()->create([
            'user_id' => $this->artistUser->id,
            'song_id' => $song->id,
            'payment_type' => 'purchase',
            'amount' => 1000,
            'status' => 'completed',
            'completed_at' => now()->addMinute(),
        ]);

        $earningsResponse = $this->actingAs($this->artistUser)->getJson('/api/artist/earnings');
        $songsResponse = $this->actingAs($this->artistUser)->getJson('/api/artist/earnings/songs');

        $earningsResponse->assertOk()
            ->assertJsonPath('data.stats.balance', 150)
            ->assertJsonPath('data.earnings_sources.0.source', 'Streaming')
            ->assertJsonPath('data.earnings_sources.0.amount', 150)
            ->assertJsonPath('data.stats.total_earnings', 1150)
            ->assertJsonPath('data.streaming_configuration.streaming_commission_percent', '20.00');

        $transactions = collect($earningsResponse->json('data.transactions'));
        $streamTransaction = $transactions->firstWhere('type', 'stream');

        $this->assertNotNull($streamTransaction);
        $this->assertSame('Streaming - '.$song->title, $streamTransaction['description']);
        $this->assertEquals(150.0, $streamTransaction['amount']);
        $this->assertEquals(200.0, $streamTransaction['gross_amount']);
        $this->assertEquals(50.0, $streamTransaction['platform_fee']);
        $this->assertSame('gold-monthly', $streamTransaction['details']['listener_plan_slug']);
        $this->assertSame('plan_metadata', $streamTransaction['details']['rate_source']);
        $this->assertSame($song->id, $streamTransaction['details']['source_song_id']);

        $songsResponse->assertOk()
            ->assertJsonPath('data.0.song_id', $song->id)
            ->assertJsonPath('data.0.streams_revenue', 150)
            ->assertJsonPath('data.0.downloads_revenue', 1000)
            ->assertJsonPath('data.0.total_revenue', 1150)
            ->assertJsonPath('data.0.play_count', 999);
    }
}

