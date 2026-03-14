<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventInterestContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_toggle_event_interest(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();

        $first = $this->actingAs($user)->postJson('/api/events/'.$event->id.'/interest');
        $first->assertOk()
            ->assertJsonPath('data.interested', true);

        $this->assertDatabaseHas('event_interests', [
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $second = $this->actingAs($user)->postJson('/api/events/'.$event->id.'/interest');
        $second->assertOk()
            ->assertJsonPath('data.interested', false);

        $this->assertDatabaseMissing('event_interests', [
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);
    }
}
