<?php

namespace Tests\Feature\Store;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Store\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSellerCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_store_grants_the_seller_capability(): void
    {
        config([
            'store.enabled' => true,
            'store.stores.allow_user_stores' => true,
            'store.stores.require_verification' => false,
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->assertFalse($user->hasCapability(Capability::Seller));

        app(StoreService::class)->create($user, [
            'name' => 'My Shop',
            'owner_mode' => 'user',
        ]);

        $this->assertTrue($user->fresh()->hasCapability(Capability::Seller));
    }
}
