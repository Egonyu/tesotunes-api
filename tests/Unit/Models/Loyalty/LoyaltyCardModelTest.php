<?php

namespace Tests\Unit\Models\Loyalty;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyReward;
use App\Models\User;
use Tests\TestCase;

class LoyaltyCardModelTest extends TestCase
{
    public function test_auto_generates_uuid_on_creation(): void
    {
        $card = LoyaltyCard::factory()->create();

        $this->assertNotNull($card->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $card->uuid
        );
    }

    public function test_auto_generates_slug_on_creation(): void
    {
        $card = LoyaltyCard::factory()->create(['name' => 'My Amazing Fan Club']);

        $this->assertStringContainsString('my-amazing-fan-club', $card->slug);
    }

    public function test_route_key_is_slug(): void
    {
        $card = new LoyaltyCard();

        $this->assertEquals('slug', $card->getRouteKeyName());
    }

    public function test_is_active_returns_true_for_active_status(): void
    {
        $card = LoyaltyCard::factory()->create(['status' => 'active']);

        $this->assertTrue($card->isActive());
    }

    public function test_is_active_returns_false_for_draft_status(): void
    {
        $card = LoyaltyCard::factory()->draft()->create();

        $this->assertFalse($card->isActive());
    }

    public function test_available_tiers_returns_tier_names(): void
    {
        $card = LoyaltyCard::factory()->create();
        $tiers = $card->availableTiers();

        $this->assertIsArray($tiers);
        $this->assertNotEmpty($tiers);
        $this->assertContains('bronze', $tiers);
    }

    public function test_tier_config_returns_specific_tier(): void
    {
        $card = LoyaltyCard::factory()->create();
        $config = $card->tierConfig('bronze');

        $this->assertNotNull($config);
        $this->assertEquals('bronze', $config['name']);
        $this->assertArrayHasKey('price_monthly', $config);
    }

    public function test_tier_config_returns_null_for_invalid_tier(): void
    {
        $card = LoyaltyCard::factory()->create();

        $this->assertNull($card->tierConfig('diamond'));
    }

    public function test_tier_price_returns_correct_price(): void
    {
        $card = LoyaltyCard::factory()->create([
            'tiers' => [
                [
                    'name'          => 'bronze',
                    'price_monthly' => 5000,
                    'price_yearly'  => 50000,
                    'benefits'      => ['Test'],
                ],
            ],
        ]);

        $this->assertEquals(5000, $card->tierPrice('bronze', 'monthly'));
        $this->assertEquals(50000, $card->tierPrice('bronze', 'yearly'));
    }

    public function test_belongs_to_artist(): void
    {
        $artist = Artist::factory()->create();
        $card   = LoyaltyCard::factory()->create(['artist_id' => $artist->id]);

        $this->assertEquals($artist->id, $card->artist->id);
    }

    public function test_has_many_members(): void
    {
        $card = LoyaltyCard::factory()->create();
        LoyaltyCardMember::factory()->count(3)->create(['loyalty_card_id' => $card->id]);

        $this->assertCount(3, $card->members);
    }

    public function test_has_many_rewards(): void
    {
        $card = LoyaltyCard::factory()->create();
        LoyaltyReward::factory()->count(2)->create(['loyalty_card_id' => $card->id]);

        $this->assertCount(2, $card->rewards);
    }

    public function test_active_scope_filters_correctly(): void
    {
        LoyaltyCard::factory()->create(['status' => 'active']);
        LoyaltyCard::factory()->draft()->create();
        LoyaltyCard::factory()->suspended()->create();

        $active = LoyaltyCard::active()->get();

        $this->assertTrue($active->every(fn ($c) => $c->status === 'active'));
    }
}
