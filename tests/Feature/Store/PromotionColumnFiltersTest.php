<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Browse used to filter promotions with JSON_EXTRACT and JSON_UNQUOTE against
 * the metadata blob: unindexable, and MySQL-only syntax in a schema that is
 * otherwise portable. The filtered attributes are columns now.
 */
class PromotionColumnFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private function listing(array $attributes): Product
    {
        $store = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        return Product::create(array_merge([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'Listing '.uniqid(),
            'slug' => 'listing-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_ACTIVE,
            'price_ugx' => 10000,
            'price_credits' => 0,
            'is_active' => true,
        ], $attributes));
    }

    public function test_browse_filters_on_the_platform_column(): void
    {
        $this->listing(['promotion_platform' => 'tiktok', 'promotion_type' => 'live_stream_promotion']);
        $this->listing(['promotion_platform' => 'instagram', 'promotion_type' => 'social_media_mention']);

        $platforms = collect($this->getJson('/api/promotions?platform=tiktok')->assertOk()->json('data'))
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['tiktok'], $platforms);
    }

    public function test_browse_filters_on_the_reach_range(): void
    {
        $this->listing(['promotion_platform' => 'tiktok', 'estimated_reach' => 500]);
        $this->listing(['promotion_platform' => 'tiktok', 'estimated_reach' => 50_000]);

        $reaches = collect($this->getJson('/api/promotions?min_reach=10000')->assertOk()->json('data'))
            ->pluck('estimated_reach')
            ->all();

        $this->assertNotEmpty($reaches);
        foreach ($reaches as $reach) {
            $this->assertGreaterThanOrEqual(10_000, $reach);
        }
    }

    public function test_browse_filters_on_the_delivery_window(): void
    {
        $this->listing(['promotion_platform' => 'tiktok', 'delivery_days_max' => 2]);
        $this->listing(['promotion_platform' => 'tiktok', 'delivery_days_max' => 30]);

        $windows = collect($this->getJson('/api/promotions?delivery_days_max=7')->assertOk()->json('data'))
            ->pluck('delivery_days_max')
            ->all();

        $this->assertNotEmpty($windows);
        foreach ($windows as $window) {
            $this->assertLessThanOrEqual(7, $window);
        }
    }

    public function test_the_seller_endpoint_persists_the_columns_not_the_blob(): void
    {
        $listing = $this->listing([
            'promotion_type' => 'live_stream_promotion',
            'promotion_platform' => 'tiktok',
            'estimated_reach' => 1000,
            'delivery_days_min' => 1,
            'delivery_days_max' => 5,
        ]);

        $row = DB::table('store_products')->where('id', $listing->id)->first();

        $this->assertSame('live_stream_promotion', $row->promotion_type);
        $this->assertSame('tiktok', $row->promotion_platform);
        $this->assertSame(1000, (int) $row->estimated_reach);
        $this->assertSame(5, (int) $row->delivery_days_max);
    }

    /**
     * The browse query must no longer reach into the JSON blob for anything
     * that became a column — that is the whole point of moving them.
     */
    public function test_browse_does_not_use_json_extract_for_promoted_attributes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/promotions?platform=tiktok&type=social_media_mention&min_reach=100&delivery_days_max=7')
            ->assertOk();

        $sql = implode(' ', $queries);

        $this->assertStringNotContainsString('$.promotion_type', $sql);
        $this->assertStringNotContainsString('$.platform"', $sql);
        $this->assertStringNotContainsString('$.estimated_reach', $sql);
        $this->assertStringNotContainsString('$.delivery_days_max', $sql);
    }
}
