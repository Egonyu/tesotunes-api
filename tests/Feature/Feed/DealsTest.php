<?php

use App\Models\FeedItem;
use App\Models\User;
use App\Modules\Store\Models\Deal;
use App\Modules\Store\Models\DealClaim;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\DealService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    Queue::fake();
    Notification::fake();
    Cache::flush();
    config(['wallet.pin.enforce' => false]);
    FeedItem::query()->delete();
    DealClaim::query()->delete();
    Deal::query()->delete();
});

function partnerDeal(array $attributes = []): Deal
{
    $store = Store::factory()->create(['status' => Store::STATUS_ACTIVE, 'city' => 'Soroti']);

    return Deal::factory()->create(array_merge(['store_id' => $store->id], $attributes))->load('store.user');
}

function memberWithCredits(int $credits, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    if ($credits > 0) {
        $user->ensureCreditWallet()->addCredits($credits, 'test_grant', 'Test credits');
    }

    return $user;
}

test('publishing a deal puts one deal card on the timeline with its live terms', function () {
    $deal = partnerDeal(['status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    app(DealService::class)->publish($deal);
    app(DealService::class)->publish($deal->fresh());

    expect(FeedItem::query()->where('type', 'deal_posted')->where('subject_id', $deal->id)->count())->toBe(1);

    $response = $this->getJson('/api/feed/timeline?module=store')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.presentation.kind'))->toBe('deal');
    expect($response->json('data.0.presentation.deal_uuid'))->toBe($deal->uuid);
    expect($response->json('data.0.presentation.credits_price'))->toBe(20);
    expect($response->json('data.0.presentation.claims_left'))->toBe(40);
    expect($response->json('data.0.presentation.claim'))->toBeNull();
    expect($response->json('data.0.identity'))->toBe('deal:'.$deal->id);
    expect($response->json('data.0.actor.type'))->toBe('partner');
});

test('a deal that is no longer live disappears from the timeline and from browsing', function () {
    $deal = partnerDeal(['status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    app(DealService::class)->publish($deal);
    $this->getJson('/api/deals')->assertOk()->assertJsonCount(1, 'data');

    $deal->update(['valid_until' => now()->subMinute()]);

    $this->getJson('/api/feed/timeline?module=store')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/deals')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/deals/'.$deal->uuid)->assertNotFound();
});

test('claiming a priced deal spends credits once and hands back a single-use code', function () {
    $deal = partnerDeal(['credits_price' => 20, 'status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    app(DealService::class)->publish($deal);
    $member = memberWithCredits(50);

    $first = $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertCreated();
    expect($first->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($first->json('data.status'))->toBe('claimed');
    expect($first->json('data.credits_spent'))->toBe(20);
    expect((float) $member->ensureCreditWallet()->fresh()->balance)->toBe(30.0);
    expect($deal->fresh()->claims_count)->toBe(1);

    $again = $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertOk();
    expect($again->json('data.token'))->toBe($first->json('data.token'));
    expect((float) $member->ensureCreditWallet()->fresh()->balance)->toBe(30.0);
    expect($deal->fresh()->claims_count)->toBe(1);

    $timeline = $this->actingAs($member)->getJson('/api/feed/timeline?module=store')->assertOk();
    expect($timeline->json('data.0.presentation.claim.status'))->toBe('claimed');
    expect($timeline->json('data.0.presentation.claims_left'))->toBe(39);
});

test('a member without enough credits cannot claim and nothing is recorded', function () {
    $deal = partnerDeal(['credits_price' => 20]);
    $member = memberWithCredits(5);

    $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertStatus(402);
    expect(DealClaim::query()->count())->toBe(0);
    expect($deal->fresh()->claims_count)->toBe(0);
    expect((float) $member->ensureCreditWallet()->fresh()->balance)->toBe(5.0);
});

test('a free deal needs no credits and guests are asked to sign in', function () {
    $deal = partnerDeal(['credits_price' => 0]);
    $this->postJson('/api/deals/'.$deal->uuid.'/claim')->assertUnauthorized();

    $member = memberWithCredits(0);
    $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertCreated()->assertJsonPath('data.credits_spent', 0);
});

test('only the partner redeems a code, once, and is paid at the published rate minus the margin', function () {
    $deal = partnerDeal(['credits_price' => 20]);
    $owner = $deal->store->user;
    $member = memberWithCredits(20);
    $stranger = User::factory()->create();
    $token = $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertCreated()->json('data.token');

    $this->actingAs($stranger)->postJson('/api/deals/redeem', ['token' => $token])->assertForbidden();
    $this->actingAs($owner)->postJson('/api/deals/redeem', ['token' => 'NOPE'])->assertNotFound();

    [$merchantUgx, $feeUgx] = app(DealService::class)->payout(20);
    $balanceBefore = (float) $owner->fresh()->ugx_balance;
    $response = $this->actingAs($owner)->postJson('/api/deals/redeem', ['token' => $token])->assertOk();
    expect($response->json('data.status'))->toBe('redeemed');
    expect((float) $response->json('data.merchant_ugx'))->toBe($merchantUgx);
    expect((float) $response->json('data.platform_fee_ugx'))->toBe($feeUgx);
    expect($merchantUgx + $feeUgx)->toBe(round(20 / max(1, (int) app(\App\Settings\SettingsManager::class)->get('credits_per_ugx')), 2));
    expect((float) $owner->fresh()->ugx_balance)->toBe(round($balanceBefore + $merchantUgx, 2));
    expect($deal->fresh()->redemptions_count)->toBe(1);

    $this->actingAs($owner)->postJson('/api/deals/redeem', ['token' => $token])->assertStatus(410);
    $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertStatus(409);
});

test('unredeemed claims expire with a refund and free their slot', function () {
    $deal = partnerDeal(['credits_price' => 20, 'max_claims' => 1]);
    $member = memberWithCredits(20);
    $this->actingAs($member)->postJson('/api/deals/'.$deal->uuid.'/claim')->assertCreated();
    expect($deal->fresh()->claimsLeft())->toBe(0);

    DealClaim::query()->update(['expires_at' => now()->subMinute()]);
    $this->artisan('deals:expire-claims')->assertSuccessful();

    expect(DealClaim::query()->first()->status)->toBe('expired');
    expect((float) $member->ensureCreditWallet()->fresh()->balance)->toBe(20.0);
    expect($deal->fresh()->claimsLeft())->toBe(1);
});

test('browsing puts the home town first and the merchant flow creates and publishes deals', function () {
    $kumi = partnerDeal(['town' => 'Kumi']);
    $soroti = partnerDeal(['town' => 'Soroti']);
    $member = memberWithCredits(0, ['city' => 'Soroti']);

    $listing = $this->actingAs($member)->getJson('/api/deals')->assertOk()->assertJsonCount(2, 'data');
    expect($listing->json('data.0.uuid'))->toBe($soroti->uuid);
    $this->getJson('/api/deals?town=Kumi')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $kumi->uuid);

    $owner = $soroti->store->user;
    $created = $this->actingAs($owner)->postJson('/api/merchant/stores/'.$soroti->store->slug.'/deals', [
        'title' => 'Free chai with breakfast', 'credits_price' => 0, 'max_claims' => 10, 'valid_until' => now()->addDays(2)->toIso8601String(), 'publish' => true,
    ])->assertCreated();
    expect($created->json('data.state'))->toBe('open');
    expect($created->json('data.town'))->toBe('Soroti');
    expect(FeedItem::query()->where('type', 'deal_posted')->where('subject_id', Deal::query()->where('uuid', $created->json('data.uuid'))->value('id'))->exists())->toBeTrue();

    $this->actingAs($member)->postJson('/api/merchant/stores/'.$soroti->store->slug.'/deals', ['title' => 'Nope'])->assertForbidden();
    $this->actingAs($owner)->getJson('/api/merchant/stores/'.$soroti->store->slug.'/deals')->assertOk()->assertJsonCount(2, 'data');
});

test('saving and hiding a deal use its identity and respect its availability', function () {
    $deal = partnerDeal();
    app(DealService::class)->publish($deal->forceFill(['status' => Deal::STATUS_DRAFT, 'published_at' => null]));
    $member = memberWithCredits(0);

    $this->actingAs($member)->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => 'deal:'.$deal->id])->assertOk()
        ->assertJsonPath('data.saved', ['deal:'.$deal->id]);
    $this->actingAs($member)->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonCount(1, 'data');

    $deal->update(['status' => Deal::STATUS_PAUSED]);
    $this->actingAs($member)->putJson('/api/feed/timeline/preferences', ['action' => 'hide', 'identity' => 'deal:'.$deal->id])->assertNotFound();
});

test('a partner sees only the storefronts they manage', function () {
    $deal = partnerDeal();
    $owner = $deal->store->user;
    $member = memberWithCredits(0);

    $this->getJson('/api/merchant/stores')->assertUnauthorized();
    $this->actingAs($member)->getJson('/api/merchant/stores')->assertOk()->assertJsonPath('data', []);
    $listing = $this->actingAs($owner)->getJson('/api/merchant/stores')->assertOk()->assertJsonCount(1, 'data');
    expect($listing->json('data.0.slug'))->toBe($deal->store->slug);
    expect($listing->json('data.0.city'))->toBe('Soroti');
});

test('a storefront waiting for approval can prepare drafts but not publish them', function () {
    $store = Store::factory()->create(['status' => Store::STATUS_PENDING, 'city' => 'Kumi']);
    $owner = $store->user;

    $this->actingAs($owner)->postJson('/api/merchant/stores/'.$store->slug.'/deals', ['title' => 'Free chai', 'publish' => true])
        ->assertStatus(409)->assertJsonPath('message', fn ($message) => str_contains($message, 'waiting for approval'));
    expect(Deal::query()->where('store_id', $store->id)->exists())->toBeFalse();

    $draft = $this->actingAs($owner)->postJson('/api/merchant/stores/'.$store->slug.'/deals', ['title' => 'Free chai'])->assertCreated();
    expect($draft->json('data.status'))->toBe('draft');
    $this->actingAs($owner)->postJson('/api/merchant/deals/'.$draft->json('data.uuid').'/publish')->assertStatus(409);
    expect(FeedItem::query()->where('type', 'deal_posted')->count())->toBe(0);

    $store->update(['status' => Store::STATUS_ACTIVE]);
    $this->actingAs($owner)->postJson('/api/merchant/deals/'.$draft->json('data.uuid').'/publish')->assertOk()->assertJsonPath('data.state', 'open');
});

test('a verified business without an artist profile opens a storefront the way the partner page sends it', function () {
    config(['store.enabled' => true, 'store.stores.allow_user_stores' => true, 'store.stores.require_verification' => true]);
    $business = User::factory()->create(['email_verified_at' => now(), 'city' => 'Kumi']);

    $response = $this->actingAs($business)->post('/api/store/seller/stores', [
        'name' => "Mama Rose's Kitchen",
        'owner_mode' => 'user',
        'city' => 'Soroti',
        'phone' => '+256700000000',
        'address' => 'Opposite the clock tower',
        'offers_local_pickup' => '1',
        'pickup_address' => 'Counter at the front',
        'settings' => ['policies' => ['return_days' => 0], 'theme' => ['primary_color' => '#E51B4C']],
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($response->json('data.status'))->toBe(Store::STATUS_PENDING);
    expect($response->json('data.city'))->toBe('Soroti');
    expect($business->fresh()->hasCapability(\App\Enums\Capability::Seller))->toBeTrue();

    $listing = $this->actingAs($business)->getJson('/api/merchant/stores')->assertOk()->assertJsonCount(1, 'data');
    expect($listing->json('data.0.status'))->toBe('pending');
    expect($listing->json('data.0.name'))->toBe("Mama Rose's Kitchen");
});

test('an unverified account cannot open a storefront', function () {
    config(['store.enabled' => true, 'store.stores.allow_user_stores' => true]);
    $unverified = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($unverified)->postJson('/api/store/seller/stores', ['name' => 'Corner Shop', 'owner_mode' => 'user'])->assertForbidden();
    expect(Store::query()->where('user_id', $unverified->id)->exists())->toBeFalse();
});
