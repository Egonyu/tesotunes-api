<?php

namespace Tests\Feature\Api\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SellerPromotionAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plain_user_cannot_access_seller_promotion_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/store/seller/promotions')
            ->assertForbidden();
    }

    public function test_artist_can_access_seller_promotion_endpoints(): void
    {
        $artist = User::factory()->create([
            'role' => 'artist',
            'is_active' => true,
        ]);

        $this->actingAs($artist, 'sanctum')
            ->getJson('/api/store/seller/promotions')
            ->assertOk();
    }
}
