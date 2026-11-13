<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Deal;
use App\Modules\Store\Models\DealClaim;
use App\Modules\Store\Models\Store;
use App\Services\CreditService;
use App\Services\FeedItemService;
use App\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The money trail of a partner deal, in one place.
 *
 * claim:   credits leave the member at once (locked against double claims),
 *          the claim gets a single-use token and an expiry.
 * redeem:  the merchant scans the token; UGX lands on the store owner's
 *          balance at the published rate minus the platform margin, both
 *          frozen on the claim so later rate changes cannot rewrite history.
 * expire:  a claim nobody redeemed gives the credits back and frees the slot.
 */
class DealService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly CreditService $credits,
    ) {}

    public function create(Store $store, array $data, bool $publish = false): Deal
    {
        abort_if(
            $publish && $store->status !== Store::STATUS_ACTIVE,
            409,
            'Your storefront is waiting for approval. Save the deal as a draft and publish it once the storefront is approved.',
        );

        $deal = $store->deals()->create([
            'title' => $data['title'],
            'discount_label' => $data['discount_label'] ?? null,
            'terms' => $data['terms'] ?? null,
            'image' => $data['image'] ?? null,
            'town' => $data['town'] ?? $store->city,
            'credits_price' => (int) ($data['credits_price'] ?? 0),
            'ugx_value' => $data['ugx_value'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'max_claims' => $data['max_claims'] ?? null,
            'status' => Deal::STATUS_DRAFT,
        ]);

        return $publish ? $this->publish($deal) : $deal;
    }

    /** Make the deal claimable and put it on the timeline once. */
    public function publish(Deal $deal): Deal
    {
        if ($deal->status === Deal::STATUS_ACTIVE && $deal->published_at) {
            return $deal;
        }
        $deal->loadMissing('store');
        abort_unless(
            $deal->store?->status === Store::STATUS_ACTIVE,
            409,
            'Your storefront is waiting for approval. Save the deal as a draft and publish it once the storefront is approved.',
        );

        $deal->forceFill(['status' => Deal::STATUS_ACTIVE, 'published_at' => $deal->published_at ?? now()])->save();
        $deal->load('store.user');
        $store = $deal->store;

        try {
            FeedItemService::create([
                'type' => 'deal_posted',
                'module' => 'store',
                'visibility' => 'public',
                'title' => $deal->title,
                'body' => $deal->terms,
                'actor_id' => $store?->user_id,
                'actor_type' => 'user',
                'actor_name' => $store?->name,
                'actor_avatar_url' => $store?->logo_url,
                'actor_verified' => (bool) $store?->is_verified,
                'subject_type' => Deal::class,
                'subject_id' => $deal->id,
                'media_type' => $deal->image ? 'image' : null,
                'media_url' => $deal->image_url,
                'region' => $deal->town,
                'published_at' => $deal->published_at,
                'expires_at' => $deal->valid_until,
                'actions' => $store?->slug ? [['type' => 'view', 'label' => 'View partner', 'url' => '/store/shops/'.$store->slug]] : [],
                'extras' => ['deal_uuid' => $deal->uuid, 'credits_price' => $deal->credits_price, 'town' => $deal->town],
            ]);
        } catch (\Throwable $error) {
            Log::error('DealService: feed item not created', ['deal_id' => $deal->id, 'error' => $error->getMessage()]);
        }

        return $deal;
    }

    public function claim(Deal $deal, User $user): DealClaim
    {
        abort_unless((bool) $this->settings->get('deals_enabled'), 404, 'Deals are not available right now.');

        return DB::transaction(function () use ($deal, $user) {
            $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->with('store')->firstOrFail();
            $existing = $locked->claimFor($user);
            if ($existing?->isRedeemable()) {
                return $existing->load('deal.store');
            }
            abort_if($existing && $existing->status === DealClaim::STATUS_REDEEMED, 409, 'You have already used this deal.');
            abort_unless($locked->isLive(), 410, 'This deal is no longer available.');

            $spent = 0;
            if (! $locked->isFree()) {
                $transaction = $this->credits->getUserWallet($user)->spendCredits(
                    $locked->credits_price,
                    'deal_claim',
                    'Deal: '.$locked->title,
                    ['reference' => 'deal_'.$locked->uuid.'_'.$user->id.'_'.now()->timestamp],
                );
                abort_if($transaction === null, 402, 'Not enough credits for this deal.');
                $spent = $locked->credits_price;
            }

            $window = max(1, (int) $this->settings->get('deals_claim_window_hours'));
            $expires = now()->addHours($window);
            if ($locked->valid_until && $locked->valid_until->lt($expires)) {
                $expires = $locked->valid_until;
            }

            $existing?->delete();
            $claim = $locked->claims()->create([
                'user_id' => $user->id,
                'credits_spent' => $spent,
                'status' => DealClaim::STATUS_CLAIMED,
                'expires_at' => $expires,
            ]);
            $locked->increment('claims_count');

            return $claim->load('deal.store');
        });
    }

    public function redeem(string $token, User $merchant): DealClaim
    {
        $claim = DealClaim::query()->where('token', trim($token))->with('deal.store.user')->first();
        abort_unless($claim, 404, 'No claim matches that code.');
        abort_unless($claim->deal?->store?->canBeManagedBy($merchant), 403, 'This code belongs to another partner.');
        abort_if($claim->status === DealClaim::STATUS_REDEEMED, 410, 'This code was already used.');
        abort_unless($claim->isRedeemable(), 410, 'This code has expired.');

        [$merchantUgx, $feeUgx, $rate] = $this->payout($claim->credits_spent);

        return DB::transaction(function () use ($claim, $merchant, $merchantUgx, $feeUgx, $rate) {
            $claim->forceFill([
                'status' => DealClaim::STATUS_REDEEMED,
                'redeemed_at' => now(),
                'redeemed_by_user_id' => $merchant->id,
                'merchant_ugx' => $merchantUgx,
                'platform_fee_ugx' => $feeUgx,
                'credit_rate_ugx' => $rate,
            ])->save();
            $claim->deal->increment('redemptions_count');
            if ($claim->credits_spent > 0) {
                $claim->deal->store->increment('total_sales_credits', $claim->credits_spent);
            }
            if ($merchantUgx > 0 && ($owner = $claim->deal->store->user)) {
                User::query()->whereKey($owner->id)->increment('ugx_balance', $merchantUgx);
            }

            return $claim;
        });
    }

    /** Give back credits on claims nobody redeemed; returns how many were expired. */
    public function expireStale(): int
    {
        $count = 0;
        DealClaim::query()->stale()->with('deal', 'user')->chunkById(200, function ($claims) use (&$count) {
            foreach ($claims as $claim) {
                DB::transaction(function () use ($claim) {
                    $claim->forceFill(['status' => DealClaim::STATUS_EXPIRED])->save();
                    if ($claim->deal && $claim->deal->claims_count > 0) {
                        $claim->deal->decrement('claims_count');
                    }
                    if ($claim->credits_spent > 0 && $claim->user) {
                        $this->credits->getUserWallet($claim->user)->addCredits(
                            $claim->credits_spent,
                            'deal_refund',
                            'Refund: '.($claim->deal?->title ?? 'deal'),
                            ['reference' => 'deal_refund_'.$claim->uuid],
                        );
                    }
                });
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array{0: float, 1: float, 2: float} merchant UGX, platform fee UGX, UGX per credit
     */
    public function payout(int $credits): array
    {
        $creditsPerUgx = max(1, (int) $this->settings->get('credits_per_ugx'));
        $rate = round(1 / $creditsPerUgx, 4);
        $gross = round($credits * $rate, 2);
        $margin = min(100, max(0, (float) $this->settings->get('deals_platform_margin_percent')));
        $fee = round($gross * $margin / 100, 2);

        return [round($gross - $fee, 2), $fee, $rate];
    }
}
