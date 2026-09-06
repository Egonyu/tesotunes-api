<?php

namespace Tests\Feature\Credits;

use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The guide is generated from the rate table the rewards engine enforces, so a
 * rate edited in the admin changes the page. These tests pin that, and pin the
 * fact that it stays readable to a guest.
 */
class CreditGuideTest extends TestCase
{
    use RefreshDatabase;

    private function rate(string $activity, array $attributes = []): CreditRate
    {
        return CreditRate::query()->updateOrCreate(
            ['activity_type' => $activity],
            array_merge([
                'display_name' => Str::headline($activity),
                'credits_per_action' => 10,
                'is_active' => true,
            ], $attributes)
        );
    }

    public function test_a_guest_can_read_the_guide(): void
    {
        $this->rate('contribution_translation', ['credits_per_action' => 200]);

        $this->getJson('/api/credits/guide')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.personalised', false);
    }

    public function test_rates_are_grouped_with_the_corpus_work_first(): void
    {
        $this->rate('social_like', ['credits_per_action' => 1, 'daily_limit' => 30]);
        $this->rate('contribution_translation', ['credits_per_action' => 200]);

        $groups = $this->getJson('/api/credits/guide')->json('data.groups');
        $keys = array_column($groups, 'key');

        $this->assertContains('contributions', $keys);
        $this->assertContains('social', $keys);
        $this->assertLessThan(
            array_search('social', $keys, true),
            array_search('contributions', $keys, true),
            'The corpus work must lead — it pays 200x a like.'
        );
    }

    public function test_the_guide_carries_the_rate_rows_verbatim(): void
    {
        $this->rate('song_play_complete', [
            'display_name' => 'Finished a song',
            'credits_per_action' => 0.5,
            'daily_limit' => 50,
            'cooldown_minutes' => 1,
            'description' => 'Paid per completed play, capped daily.',
        ]);

        $groups = $this->getJson('/api/credits/guide')->json('data.groups');
        $row = collect($groups)->flatMap(fn ($g) => $g['rates'])
            ->firstWhere('activity_type', 'song_play_complete');

        $this->assertSame('Finished a song', $row['label']);
        $this->assertSame(0.5, (float) $row['credits']);
        $this->assertSame(50.0, (float) $row['daily_limit']);
        $this->assertSame(1, $row['cooldown_minutes']);
        $this->assertSame('Paid per completed play, capped daily.', $row['description']);
    }

    public function test_an_inactive_rate_is_not_advertised(): void
    {
        $this->rate('social_share', ['is_active' => false]);

        $activities = collect($this->getJson('/api/credits/guide')->json('data.groups'))
            ->flatMap(fn ($g) => $g['rates'])
            ->pluck('activity_type');

        $this->assertNotContains('social_share', $activities);
    }

    public function test_an_unknown_activity_still_appears(): void
    {
        $this->rate('brand_new_reward', ['credits_per_action' => 7]);

        $groups = $this->getJson('/api/credits/guide')->json('data.groups');
        $activities = collect($groups)->flatMap(fn ($g) => $g['rates'])->pluck('activity_type');

        $this->assertContains(
            'brand_new_reward',
            $activities,
            'A rate added in the admin must reach the guide without a deploy.'
        );
    }

    public function test_a_signed_in_reader_sees_their_own_allowance(): void
    {
        $this->rate('social_like', ['credits_per_action' => 1, 'daily_limit' => 30]);

        $user = User::factory()->create();
        $user->ensureCreditWallet();
        $user->creditTransactions()->create([
            'uuid' => (string) Str::uuid(),
            'type' => CreditTransaction::TYPE_EARNED,
            'amount' => 12,
            'balance_after' => 12,
            'source' => 'social_like',
            'referenceable_type' => User::class,
            'referenceable_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/credits/guide')->assertOk();
        $row = collect($response->json('data.groups'))
            ->flatMap(fn ($g) => $g['rates'])
            ->firstWhere('activity_type', 'social_like');

        $this->assertTrue($response->json('data.personalised'));
        $this->assertSame(12.0, (float) $row['earned_today']);
        $this->assertSame(18.0, (float) $row['remaining_today']);
    }

    public function test_a_guest_row_carries_no_personal_fields(): void
    {
        $this->rate('daily_login', ['credits_per_action' => 10, 'cooldown_minutes' => 1440]);

        $row = collect($this->getJson('/api/credits/guide')->json('data.groups'))
            ->flatMap(fn ($g) => $g['rates'])
            ->firstWhere('activity_type', 'daily_login');

        $this->assertArrayNotHasKey('earned_today', $row);
        $this->assertArrayNotHasKey('remaining_today', $row);
    }
}
