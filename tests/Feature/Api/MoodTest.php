<?php

namespace Tests\Feature\Api;

use App\Models\Mood;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_active_moods(): void
    {
        Mood::factory()->create(['name' => 'Happy', 'slug' => 'happy', 'is_active' => true]);
        Mood::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        $this->getJson('/api/content/moods')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['slug' => 'happy'])
            ->assertJsonMissing(['slug' => 'hidden']);
    }

    public function test_show_resolves_a_mood_by_slug(): void
    {
        Mood::factory()->create(['name' => 'Motivational', 'slug' => 'motivational', 'is_active' => true]);

        $this->getJson('/api/content/moods/motivational')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'motivational');
    }

    public function test_show_also_resolves_by_numeric_id(): void
    {
        $mood = Mood::factory()->create(['slug' => 'chill', 'is_active' => true]);

        $this->getJson("/api/content/moods/{$mood->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mood->id);
    }

    public function test_show_returns_404_for_unknown_mood(): void
    {
        $this->getJson('/api/content/moods/does-not-exist')
            ->assertNotFound();
    }
}
