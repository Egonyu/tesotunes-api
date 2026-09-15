<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Web sessions used to die after 24 hours of absence, so returning users saw
 * stale stats until they signed in again. Tokens now live 14 days and are
 * rotated on use, with a short grace period for the replaced token.
 */
class TokenLifetimeTest extends TestCase
{
    use DatabaseTransactions;

    private function issueToken(): array
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return [$user, $user->createToken('auth_token')->plainTextToken];
    }

    private function authedGet(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/auth/user');
    }

    public function test_expiration_is_set_and_never_null(): void
    {
        $this->assertSame(20160, (int) config('sanctum.expiration'));
    }

    public function test_token_survives_a_week_away(): void
    {
        [, $token] = $this->issueToken();

        $this->travel(7)->days();

        $this->authedGet($token)->assertOk();
    }

    public function test_token_is_rejected_after_fourteen_days(): void
    {
        [, $token] = $this->issueToken();

        $this->travel(14)->days();
        $this->travel(1)->minutes();

        $this->authedGet($token)->assertUnauthorized();
    }

    public function test_refresh_keeps_the_old_token_briefly_then_expires_it(): void
    {
        [, $oldToken] = $this->issueToken();

        $this->travel(13)->days();

        $response = $this->withToken($oldToken)->postJson('/api/auth/refresh')->assertOk();
        $newToken = $response->json('token');
        $this->assertNotEmpty($newToken);

        // In-flight requests with the old token still land.
        $this->authedGet($oldToken)->assertOk();

        $this->travel(2)->minutes();

        $this->authedGet($oldToken)->assertUnauthorized();
        $this->authedGet($newToken)->assertOk();

        // And the rotated token restarts the 14-day clock.
        $this->travel(10)->days();
        $this->authedGet($newToken)->assertOk();
    }

    public function test_refresh_does_not_leave_non_expiring_tokens_behind(): void
    {
        [$user, $token] = $this->issueToken();

        $this->withToken($token)->postJson('/api/auth/refresh')->assertOk();

        $withoutExpiry = PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', $user->getMorphClass())
            ->whereNull('expires_at')
            ->count();

        $this->assertSame(1, $withoutExpiry, 'Only the newly issued token should lack an expires_at.');
    }
}
