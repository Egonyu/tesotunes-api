<?php

namespace Tests\Feature\Store;

use App\Enums\Capability;
use App\Models\User;
use App\Services\Accounts\CapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerPromotionsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_promotions_are_gated_on_the_seller_capability_not_the_artist_role(): void
    {
        // A non-artist user without the seller capability is denied.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/store/seller/promotions')
            ->assertForbidden();

        // Granting the seller capability (as opening a shop now does) lets the
        // same non-artist user through the gate.
        app(CapabilityService::class)->grant($user, Capability::Seller);

        $response = $this->actingAs($user->fresh())->getJson('/api/store/seller/promotions');

        $this->assertNotSame(403, $response->status());
    }
}
