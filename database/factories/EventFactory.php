<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Event;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventTypes = ['concert', 'festival', 'meetup', 'workshop', 'other'];
        $categories = ['music', 'entertainment', 'educational', 'cultural', 'business'];
        $ugandanCities = ['Kampala', 'Entebbe', 'Jinja', 'Mbarara', 'Gulu', 'Masaka', 'Mukono', 'Kasese'];

        $eventNames = [
            'Afrobeats Night', 'Jazz Under the Stars', 'Gospel Celebration', 'Hip Hop Cypher',
            'Acoustic Sessions', 'Dance Music Festival', 'Traditional Music Concert',
            'Youth Music Awards', 'Record Label Showcase', 'Music Production Workshop',
            'Artist Meet & Greet', 'Album Launch Party', 'Music Video Premiere',
            'Charity Concert', 'Cultural Music Festival'
        ];

        $venues = [
            'Kampala Serena Hotel', 'National Theatre Uganda', 'Cricket Oval Lugogo',
            'Speke Resort Munyonyo', 'Imperial Royale Hotel', 'Mestil Hotel',
            'Sheraton Kampala Hotel', 'Kyadondo Rugby Club', 'Garden City Mall',
            'Acacia Mall', 'Nakivubo Stadium', 'Kololo Airstrip', 'Hotel Africana',
            'Silver Springs Hotel', 'Protea Hotel Entebbe'
        ];

        $startDate = $this->faker->dateTimeBetween('now', '+6 months');
        $endDate = clone $startDate;
        $endDate->modify('+' . rand(2, 8) . ' hours');

        return [
            'organizer_id' => \App\Models\User::factory(),  // Changed from user_id
            'organizer_type' => 'user',  // NEW field
            'title' => $this->faker->randomElement($eventNames),
            'slug' => fn(array $attributes) => \Str::slug($attributes['title'] . '-' . $this->faker->randomNumber(4)),
            'description' => $this->faker->paragraphs(3, true),
            'artwork' => 'events/covers/event_' . $this->faker->numberBetween(1, 10) . '.jpg',
            'category' => $this->faker->randomElement($categories),
            'starts_at' => $startDate,
            'ends_at' => $endDate,
            'timezone' => 'Africa/Kampala',
            'status' => $this->faker->randomElement(['draft', 'published', 'cancelled', 'completed']),
            'visibility' => 'public',  // NEW field
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'is_published' => true,
            'published_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    /**
     * Indicate that the event is upcoming.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $this->faker->dateTimeBetween('now', '+3 months'),
            'ends_at' => fn(array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(rand(2, 8)),
        ]);
    }

    /**
     * Indicate that the event is a concert.
     */
    public function concert(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => 'concert',
            'category' => 'music',
        ]);
    }
}