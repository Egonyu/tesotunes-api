<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFunnelTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_event_funnel_touch_is_recorded_once_per_session_and_source_per_day(): void
    {
        $event = Event::factory()->published()->create();

        $payload = [
            'stage' => 'visit',
            'session_key' => 'session-alpha',
            'source' => 'tesotunes_promote',
            'channel' => 'featured_banner',
            'campaign_code' => 'kampala-launch',
            'landing_page' => '/events/'.$event->id.'?campaign=kampala-launch',
        ];

        $this->postJson('/api/events/'.$event->id.'/funnel-touch', $payload)
            ->assertCreated()
            ->assertJsonPath('data.stage', 'visit')
            ->assertJsonPath('data.source_label', 'kampala-launch');

        $this->postJson('/api/events/'.$event->id.'/funnel-touch', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('event_funnel_touchpoints', 1);
    }

    public function test_artist_event_analytics_include_funnel_totals_and_sources(): void
    {
        $artist = User::factory()->create([
            'role' => 'artist',
            'is_active' => true,
        ]);

        $event = Event::factory()->published()->create([
            'organizer_id' => $artist->id,
            'user_id' => $artist->id,
        ]);

        $this->postJson('/api/events/'.$event->id.'/funnel-touch', [
            'stage' => 'visit',
            'session_key' => 'session-one',
            'source' => 'tesotunes_promote',
            'channel' => 'featured_banner',
            'campaign_code' => 'teso-boost',
        ])->assertCreated();

        $this->postJson('/api/events/'.$event->id.'/funnel-touch', [
            'stage' => 'checkout_start',
            'session_key' => 'session-one',
            'source' => 'tesotunes_promote',
            'channel' => 'featured_banner',
            'campaign_code' => 'teso-boost',
        ])->assertCreated();

        $response = $this->actingAs($artist, 'sanctum')
            ->getJson('/api/artist/events/'.$event->id.'/analytics');

        $response->assertOk()
            ->assertJsonPath('data.funnel.totals.visits', 1)
            ->assertJsonPath('data.funnel.totals.checkout_starts', 1)
            ->assertJsonPath('data.funnel.totals.paid_orders', 0)
            ->assertJsonPath('data.funnel.by_source.0.label', 'teso-boost')
            ->assertJsonPath('data.funnel.by_source.0.visit_to_checkout_rate', 100);
    }
}
