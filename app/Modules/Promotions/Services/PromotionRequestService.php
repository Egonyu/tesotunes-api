<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Promotions\Notifications\ApplicationAwardedNotification;
use App\Modules\Promotions\Notifications\ApplicationRejectedNotification;
use App\Modules\Promotions\Notifications\ApplicationSubmittedNotification;
use App\Modules\Promotions\Notifications\PromotionRequestPostedNotification;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionRequestService
{
    /**
     * Post a new promotion promotion request for a piece of content.
     *
     * @param  Model  $promotable  Song, Album, or Event
     * @param  array<string, mixed>  $data
     */
    public function createForContent(User $creator, Model $promotable, array $data): PromotionRequest
    {
        return DB::transaction(function () use ($creator, $promotable, $data): PromotionRequest {
            $promotionRequest = PromotionRequest::create([
                'created_by_user_id' => $creator->id,
                'promotable_type' => $promotable->getMorphClass(),
                'promotable_id' => $promotable->getKey(),
                'title' => $data['title'],
                'brief' => $data['brief'] ?? null,
                'target_platforms' => $data['target_platforms'] ?? null,
                'target_audience_niches' => $data['target_audience_niches'] ?? null,
                'target_regions' => $data['target_regions'] ?? null,
                'budget_min_ugx' => $data['budget_min_ugx'] ?? 0,
                'budget_max_ugx' => $data['budget_max_ugx'] ?? 0,
                'budget_credits' => $data['budget_credits'] ?? 0,
                'max_awards' => $data['max_awards'] ?? 1,
                'deadline_at' => $data['deadline_at'] ?? null,
                'deliverables' => $data['deliverables'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Notify matching promoters asynchronously
            $creator->notify(new PromotionRequestPostedNotification($promotionRequest));

            return $promotionRequest;
        });
    }

    /**
     * Submit a promoter's application to a promotion request.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(PromotionRequest $promotionRequest, User $applicant, array $data): PromotionApplication
    {
        $profile = $applicant->promoterProfile;

        if (! $profile) {
            throw new \RuntimeException('User must complete promoter onboarding before applying.');
        }

        if (! in_array($promotionRequest->status, [PromotionRequest::STATUS_OPEN, PromotionRequest::STATUS_REVIEWING])) {
            throw new \RuntimeException('This promotion request is no longer accepting applications.');
        }

        $existing = PromotionApplication::where('promotion_request_id', $promotionRequest->id)
            ->where('promoter_profile_id', $profile->id)
            ->exists();

        if ($existing) {
            throw new \RuntimeException('You have already applied to this promotion request.');
        }

        return DB::transaction(function () use ($promotionRequest, $applicant, $profile, $data): PromotionApplication {
            $application = PromotionApplication::create([
                'promotion_request_id' => $promotionRequest->id,
                'promoter_profile_id' => $profile->id,
                'applicant_user_id' => $applicant->id,
                'proposed_price_ugx' => $data['proposed_price_ugx'] ?? 0,
                'proposed_price_credits' => $data['proposed_price_credits'] ?? 0,
                'pitch_message' => $data['pitch_message'] ?? null,
                'proposed_deliverables' => $data['proposed_deliverables'] ?? null,
                'proposed_timeline_days' => $data['proposed_timeline_days'] ?? null,
            ]);

            // Notify promotion request creator
            $promotionRequest->creator?->notify(new ApplicationSubmittedNotification($application));

            return $application;
        });
    }

    /**
     * Award a promotion request slot to an application.
     *
     * Awarding is a transaction in both senses: the artist pays the agreed
     * price into escrow (a paid store order in the promoter's store) inside
     * the same DB transaction that marks the application awarded. The
     * promoter's proceeds settle to the ledger later, when their delivery
     * proof is verified. Remaining applications are only auto-rejected once
     * every award slot is filled.
     *
     * @param  array{payment_method: string}  $payment  'ugx' or 'credits'
     */
    public function award(PromotionRequest $promotionRequest, PromotionApplication $application, array $payment = ['payment_method' => 'ugx']): bool
    {
        if ($application->promotion_request_id !== $promotionRequest->id) {
            throw new \InvalidArgumentException('Application does not belong to this promotion request.');
        }

        return DB::transaction(function () use ($promotionRequest, $application, $payment): bool {
            /**
             * Re-read the promotion request under a row lock before deciding.
             *
             * The slot check used to run before the transaction against the
             * instance the controller hydrated, and award() then wrote a
             * computed absolute count rather than an atomic increment. Two
             * concurrent awards on a one-slot brief both read awarded_count
             * as 0, both passed, and both wrote 1 — two winners, two funded
             * escrow orders, one slot. Nothing in the schema stops that, so
             * the lock is what makes the check mean anything.
             */
            $promotionRequest = PromotionRequest::query()
                ->lockForUpdate()
                ->findOrFail($promotionRequest->id);

            if (! $promotionRequest->hasOpenSlots()) {
                throw new \LogicException('All award slots for this promotion request are filled.');
            }

            $awarded = $promotionRequest->award($application);

            if (! $awarded) {
                return false;
            }

            $application->transitionTo(PromotionApplication::STATUS_AWARDED);

            $order = $this->createEscrowOrder($promotionRequest, $application, $payment);
            $application->forceFill(['order_id' => $order->id])->save();

            // Auto-reject the rest only once every slot is filled.
            if (! $promotionRequest->fresh()->hasOpenSlots()) {
                PromotionApplication::where('promotion_request_id', $promotionRequest->id)
                    ->whereIn('status', [PromotionApplication::STATUS_SUBMITTED, PromotionApplication::STATUS_SHORTLISTED])
                    ->each(function (PromotionApplication $other): void {
                        $other->reject('Another application was selected.');
                        $other->applicant?->notify(new ApplicationRejectedNotification($other));
                    });
            }

            $application->applicant?->notify(new ApplicationAwardedNotification($application));

            return true;
        });
    }

    /**
     * Pay the agreed price into platform escrow as a store order in the
     * promoter's store. The existing proof -> verify -> settle pipeline
     * releases the funds to the promoter.
     */
    private function createEscrowOrder(PromotionRequest $promotionRequest, PromotionApplication $application, array $payment): Order
    {
        // Read the buyer fresh — a cached relation could carry a stale balance.
        $buyer = User::query()->find($promotionRequest->created_by_user_id);
        $profile = $application->promoterProfile;
        $store = $profile?->store;

        if (! $buyer || ! $store) {
            throw new \RuntimeException('The promoter has no store to receive this deal — onboarding is incomplete.');
        }

        $priceUgx = round((float) ($application->proposed_price_ugx ?: $promotionRequest->budget_max_ugx), 2);
        $priceCredits = (int) ($application->proposed_price_credits ?: $promotionRequest->budget_credits);
        $method = $payment['payment_method'] ?? 'ugx';

        if ($method === 'credits') {
            $balance = (float) ($buyer->creditWallet?->available_credits ?? $buyer->credits ?? 0);
            if ($priceCredits <= 0) {
                throw new \DomainException('This deal has no credits price — pay with UGX instead.');
            }
            if ($balance < $priceCredits) {
                throw new \DomainException('Insufficient credits to fund this deal.');
            }
        } else {
            if ($priceUgx <= 0) {
                throw new \DomainException('This deal has no agreed price yet.');
            }
            if ((float) $buyer->ugx_balance < $priceUgx) {
                throw new \DomainException('Insufficient wallet balance to fund this deal.');
            }
        }

        $paidUgx = $method === 'ugx' ? $priceUgx : 0.0;
        $paidCredits = $method === 'credits' ? $priceCredits : 0;
        $dealProduct = $this->ensureDealProduct($store, $profile->display_name);

        $order = Order::create([
            'order_number' => Order::generateOrderNumber(),
            'store_id' => $store->id,
            'user_id' => $buyer->id,
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'payment_method' => $method,
            'payment_provider' => $method === 'credits' ? 'credits' : 'wallet',
            'subtotal_ugx' => $paidUgx,
            'subtotal_credits' => $paidCredits,
            'total_ugx' => $paidUgx,
            'total_credits' => $paidCredits,
            'total_amount' => $paidUgx,
            'credit_amount' => $paidCredits,
            'paid_ugx' => $paidUgx,
            'paid_credits' => $paidCredits,
            'paid_at' => now(),
            'customer_notes' => "Promotion request award: {$promotionRequest->title}",
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $dealProduct->id,
            'product_name' => "Promotion deal — {$promotionRequest->title}",
            'product_type' => 'promotion',
            'quantity' => 1,
            'unit_price' => $paidUgx,
            'price_ugx' => $paidUgx,
            'price_credits' => $paidCredits,
            'payment_method' => $method,
            'subtotal' => $paidUgx,
            'total_amount' => $paidUgx,
            'fulfillment_status' => OrderItem::STATUS_PENDING,
            'verification_status' => 'pending',
            'promotable_type' => $promotionRequest->promotable_type,
            'promotable_id' => $promotionRequest->promotable_id,
            'promotion_request_id' => $promotionRequest->id,
            'application_id' => $application->id,
        ]);

        $breakdown = app(PromotionSettlementService::class)->buildBreakdown($order, $dealProduct, $store->user);
        $item->forceFill([
            'product_snapshot' => [
                'promotion request' => ['id' => $promotionRequest->id, 'uuid' => $promotionRequest->uuid, 'title' => $promotionRequest->title],
                'promotion_settlement' => $breakdown,
            ],
        ])->save();

        app(PromotionSettlementService::class)->chargeBuyer(
            $buyer,
            $method === 'credits' ? $paidCredits : 0,
            $method === 'credits' ? 0.0 : $paidUgx,
            'promotion_award',
            "Promotion request award {$order->order_number}",
            ['order_id' => $order->id, 'promotion_request_id' => $promotionRequest->id]
        );

        return $order;
    }

    /**
     * Awarded deals need a product row for the order-item FK; each promoter
     * store carries one hidden "deal" product for that purpose.
     */
    private function ensureDealProduct(\App\Modules\Store\Models\Store $store, string $promoterName): Product
    {
        return Product::firstOrCreate(
            ['store_id' => $store->id, 'slug' => "promotion-deal-{$store->id}"],
            [
                'uuid' => (string) Str::uuid(),
                'name' => "Custom promotion deal — {$promoterName}",
                'product_type' => 'promotion',
                'status' => 'draft',
                'price_ugx' => 0,
                'price_credits' => 0,
                'is_active' => false,
            ]
        );
    }

    /**
     * Shortlist an application for the artist's review.
     */
    public function shortlist(PromotionApplication $application): bool
    {
        return $application->transitionTo(PromotionApplication::STATUS_SHORTLISTED);
    }

    /**
     * Withdraw an application (by the applicant themselves).
     */
    public function withdrawApplication(PromotionApplication $application, User $user): bool
    {
        if ($application->applicant_user_id !== $user->id) {
            throw new \InvalidArgumentException('You can only withdraw your own application.');
        }

        return $application->withdraw();
    }
}
