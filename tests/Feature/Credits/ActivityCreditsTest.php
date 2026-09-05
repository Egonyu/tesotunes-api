<?php

namespace Tests\Feature\Credits;

use App\Models\Artist;
use App\Models\CreditRate;
use App\Models\CreditTransaction;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Everyday activity credits.
 *
 * Ten of the twelve rates on /admin/rewards had never paid a single credit.
 * They were wired to an AwardCreditsForActivity listener that was never
 * registered, listening for events — SongLiked, CommentCreated, UserLoggedIn
 * — that were never written. The rates sat on the admin screen looking live.
 *
 * The award now happens where the action completes, through
 * RewardRuleService, so the daily limits and cooldowns configured on that
 * screen are the ones that actually apply.
 */
class ActivityCreditsTest extends TestCase
{
    use DatabaseTransactions;

    private function rate(string $activity, float $credits, ?float $dailyLimit = null, ?int $cooldown = null): CreditRate
    {
        return CreditRate::updateOrCreate(
            ['activity_type' => $activity],
            [
                'display_name' => $activity,
                'credits_per_action' => $credits,
                'daily_limit' => $dailyLimit,
                'cooldown_minutes' => $cooldown,
                'is_active' => true,
            ],
        );
    }

    private function creditsFrom(User $user, string $source): float
    {
        return (float) CreditTransaction::where('user_id', $user->id)
            ->where('source', $source)
            ->sum('amount');
    }

    public function test_liking_something_pays_the_configured_rate(): void
    {
        $this->rate(CreditRate::SOCIAL_LIKE, 1, dailyLimit: 30);
        $user = User::factory()->create();
        $song = Song::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/like/song/{$song->id}")
            ->assertOk();

        $this->assertEquals(1, $this->creditsFrom($user, CreditRate::SOCIAL_LIKE));
    }

    public function test_unliking_does_not_pay_and_relking_is_held_by_the_daily_limit(): void
    {
        $this->rate(CreditRate::SOCIAL_LIKE, 1, dailyLimit: 2);
        $user = User::factory()->create();
        $song = Song::factory()->create();

        // like, unlike, like, unlike, like — three likes, ceiling of two.
        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)->postJson("/api/like/song/{$song->id}")->assertOk();
        }

        $this->assertEquals(
            2,
            $this->creditsFrom($user, CreditRate::SOCIAL_LIKE),
            'The daily ceiling from /admin/rewards is what stops like-farming.'
        );
    }

    public function test_following_an_artist_pays_once_per_follow(): void
    {
        $this->rate(CreditRate::SOCIAL_FOLLOW, 1, dailyLimit: 30);
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow")->assertOk();
        // Already following — returns early, so no second award.
        $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow")->assertOk();

        $this->assertEquals(1, $this->creditsFrom($user, CreditRate::SOCIAL_FOLLOW));
    }

    public function test_creating_a_playlist_pays(): void
    {
        $this->rate(CreditRate::PLAYLIST_CREATE, 5, dailyLimit: 25);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/playlists', ['name' => 'Teso Evening'])
            ->assertCreated();

        $this->assertEquals(5, $this->creditsFrom($user, CreditRate::PLAYLIST_CREATE));
    }

    public function test_logging_in_pays_once_a_day_because_the_rate_says_so(): void
    {
        $this->rate(CreditRate::DAILY_LOGIN, 10, cooldown: 1440);
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'email_verified_at' => now(),
        ]);

        $credentials = ['email' => $user->email, 'password' => 'Password123!'];

        $this->postJson('/api/auth/login', $credentials)->assertOk();
        $this->postJson('/api/auth/login', $credentials)->assertOk();

        $this->assertEquals(
            10,
            $this->creditsFrom($user, CreditRate::DAILY_LOGIN),
            'The 1440-minute cooldown on the rate is what makes this once a day.'
        );
    }

    public function test_an_inactive_rate_pays_nothing(): void
    {
        $rate = $this->rate(CreditRate::SOCIAL_LIKE, 1, dailyLimit: 30);
        $rate->update(['is_active' => false]);

        $user = User::factory()->create();
        $song = Song::factory()->create();

        $this->actingAs($user)->postJson("/api/like/song/{$song->id}")->assertOk();

        $this->assertEquals(0, $this->creditsFrom($user, CreditRate::SOCIAL_LIKE));
    }

    /**
     * A credit failure must never break the thing that earned it.
     */
    public function test_the_action_still_succeeds_when_no_rate_is_configured(): void
    {
        CreditRate::where('activity_type', CreditRate::SOCIAL_LIKE)->delete();

        $user = User::factory()->create();
        $song = Song::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/like/song/{$song->id}")
            ->assertOk();

        $this->assertEquals(0, $this->creditsFrom($user, CreditRate::SOCIAL_LIKE));
    }
}
