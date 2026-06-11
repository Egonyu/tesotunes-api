<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PromotionStoreProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_artist_promotion_creation_auto_provisions_pending_store(): void
    {
        config([
            'store.enabled' => true,
            'store.stores.allow_user_stores' => true,
        ]);

        $artistUser = User::factory()->create([
            'role' => 'artist',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $artistUser->id,
            'status' => 'active',
            'stage_name' => 'Auto Store Artist',
        ]);

        // Merged promotions model: listing promotion services requires the
        // promoter capability — selling promo work makes you a promoter,
        // regardless of also being an artist.
        app(\App\Services\Accounts\CapabilityService::class)->grant(
            $artistUser,
            \App\Enums\Capability::Promoter,
        );

        $response = $this->actingAs($artistUser, 'sanctum')->postJson('/api/promotions', [
            'title' => 'Instagram Reel Push',
            'short_description' => 'One reel and story support',
            'description' => 'Launch support for a new single with one reel and story sequence.',
            'type' => 'social_media_mention',
            'platform' => 'instagram',
            'price_credits' => 500,
            'price_ugx' => 5000,
            'accepts_credits' => true,
            'accepts_ugx' => true,
            'accepts_hybrid' => true,
            'estimated_reach' => 2000,
            'audience_niches' => ['afrobeats'],
            'audience_regions' => ['Kampala'],
            'content_formats' => ['reel'],
            'delivery_days_min' => 1,
            'delivery_days_max' => 3,
            'deliverables' => ['1 Instagram reel', '2 story frames'],
            'terms' => 'Artist supplies approved artwork and audio snippets.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('promotion.title', 'Instagram Reel Push')
            ->assertJsonPath('promotion.status', 'pending');

        $store = $artistUser->fresh()->store;

        $this->assertNotNull($store);
        $this->assertSame(Store::STATUS_PENDING, $store->status);
        $this->assertSame(Store::TYPE_ARTIST, $store->store_type);
        $this->assertSame($artist->id, $store->owner_id);
        $this->assertSame(Artist::class, $store->owner_type);

        $this->assertDatabaseHas((new Product)->getTable(), [
            'store_id' => $store->id,
            'name' => 'Instagram Reel Push',
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_DRAFT,
        ]);
    }

    public function test_admin_can_approve_pending_artist_store(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $artistUser = User::factory()->create([
            'role' => 'artist',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $artistUser->id,
            'status' => 'active',
        ]);

        $store = Store::factory()->create([
            'user_id' => $artistUser->id,
            'owner_id' => $artist->id,
            'owner_type' => Artist::class,
            'store_type' => Store::TYPE_ARTIST,
            'status' => Store::STATUS_PENDING,
            'is_verified' => false,
            'verified_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/store/shops/{$store->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        $store->refresh();

        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertTrue((bool) $store->is_verified);
        $this->assertNotNull($store->verified_at);
    }
}
