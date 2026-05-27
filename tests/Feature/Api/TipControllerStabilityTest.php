<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\User;
use App\Models\UserCredit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: POST /api/tips was returning a generic HTTP 500
 * ("An unexpected error occurred.") whenever a tip was sent
 * without an optional `message`. The validator allows the field
 * to be omitted (nullable), but the notification body line read
 * `$validated['message']` twice without `??`, which on PHP 8 +
 * Laravel triggers an "Undefined array key" warning that the
 * framework converts to ErrorException → 500.
 *
 * These tests pin the contract for both shapes: tip with message
 * and tip without message must both return 201, and the credit
 * spend must be correctly reflected.
 */
class TipControllerStabilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $tipper;

    private Artist $recipientArtist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tipper = User::factory()->create([
            'is_active' => true,
            'role' => 'user',
        ]);

        UserCredit::query()->updateOrCreate(
            ['user_id' => $this->tipper->id],
            ['balance' => 500, 'currency' => 'credits'],
        );

        $recipientUser = User::factory()->create([
            'is_active' => true,
            'role' => 'artist',
        ]);

        $this->recipientArtist = Artist::factory()->create([
            'user_id' => $recipientUser->id,
            'status' => Artist::STATUS_APPROVED,
        ]);
    }

    public function test_tip_without_message_returns_201(): void
    {
        $response = $this->actingAs($this->tipper)
            ->postJson('/api/tips', [
                'recipient_id' => $this->recipientArtist->id,
                'recipient_type' => 'artist',
                'amount' => 10,
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['tip_id', 'amount', 'credits_remaining']]);

        $this->assertSame(10, $response->json('data.amount'));
        $this->assertSame(490, $response->json('data.credits_remaining'));
    }

    public function test_tip_with_message_returns_201(): void
    {
        $response = $this->actingAs($this->tipper)
            ->postJson('/api/tips', [
                'recipient_id' => $this->recipientArtist->id,
                'recipient_type' => 'artist',
                'amount' => 25,
                'message' => 'Keep dropping fire',
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertSame(25, $response->json('data.amount'));
        $this->assertSame(475, $response->json('data.credits_remaining'));
    }

    public function test_tip_with_null_message_returns_201(): void
    {
        $response = $this->actingAs($this->tipper)
            ->postJson('/api/tips', [
                'recipient_id' => $this->recipientArtist->id,
                'recipient_type' => 'artist',
                'amount' => 5,
                'message' => null,
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);
    }
}
