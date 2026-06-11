<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Promotions\Notifications\ApplicationAwardedNotification;
use App\Modules\Promotions\Notifications\ApplicationRejectedNotification;
use App\Modules\Promotions\Notifications\ApplicationSubmittedNotification;
use App\Modules\Promotions\Notifications\OpportunityPostedNotification;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpportunityService
{
    /**
     * Post a new promotion opportunity for a piece of content.
     *
     * @param  Model  $promotable  Song, Album, or Event
     * @param  array<string, mixed>  $data
     */
    public function createForContent(User $creator, Model $promotable, array $data): PromotionOpportunity
    {
        return DB::transaction(function () use ($creator, $promotable, $data): PromotionOpportunity {
            $opportunity = PromotionOpportunity::create([
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
            $creator->notify(new OpportunityPostedNotification($opportunity));

            return $opportunity;
        });
    }

    /**
     * Submit a promoter's application to an opportunity.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(PromotionOpportunity $opportunity, User $applicant, array $data): PromotionApplication
    {
        $profile = $applicant->promoterProfile;

        if (! $profile) {
            throw new \RuntimeException('User must complete promoter onboarding before applying.');
        }

        if (! in_array($opportunity->status, [PromotionOpportunity::STATUS_OPEN, PromotionOpportunity::STATUS_REVIEWING])) {
            throw new \RuntimeException('This opportunity is no longer accepting applications.');
        }

        $existing = PromotionApplication::where('opportunity_id', $opportunity->id)
            ->where('promoter_profile_id', $profile->id)
            ->exists();

        if ($existing) {
            throw new \RuntimeException('You have already applied to this opportunity.');
        }

        return DB::transaction(function () use ($opportunity, $applicant, $profile, $data): PromotionApplication {
            $application = PromotionApplication::create([
                'opportunity_id' => $opportunity->id,
                'promoter_profile_id' => $profile->id,
                'applicant_user_id' => $applicant->id,
                'proposed_price_ugx' => $data['proposed_price_ugx'] ?? 0,
                'proposed_price_credits' => $data['proposed_price_credits'] ?? 0,
                'pitch_message' => $data['pitch_message'] ?? null,
                'proposed_deliverables' => $data['proposed_deliverables'] ?? null,
                'proposed_timeline_days' => $data['proposed_timeline_days'] ?? null,
            ]);

            // Notify opportunity creator
            $opportunity->creator?->notify(new ApplicationSubmittedNotification($application));

            return $application;
        });
    }

    /**
     * Award an opportunity slot to an application.
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
    public function award(PromotionOpportunity $opportunity, PromotionApplication $application, array $payment = ['payment_method' => 'ugx']): bool
    {
        if ($application->opportunity_id !== $opportunity->id) {
            throw new \InvalidArgumentException('Application does not belong to this opportunity.');
        }

        if (! $opportunity->hasOpenSlots()) {
            throw new \LogicException('All award slots for this opportunity are filled.');
        }

        return DB::transaction(function () use ($opportunity, $application, $payment): bool {
            $awarded = $opportunity->award($application);

            if (! $awarded) {
                return false;
            }

            $application->transitionTo(PromotionApplication::STATUS_AWARDED);

            $order = $this->createEscrowOrder($opportunity, $application, $payment);
            $application->forceFill(['order_id' => $order->id])->save();

            // Auto-reject the rest only once every slot is filled.
            if (! $opportunity->fresh()->hasOpenSlots()) {
                PromotionApplication::where('opportunity_id', $opportunity->id)
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
    private function createEscrowOrder(PromotionOpportunity $opportunity, PromotionApplication $application, array $payment): Order
    {
        // Read the buyer fresh — a cached relation could carry a stale balance.
        $buyer = User::query()->find($opportunity->created_by_user_id);
        $profile = $application->promoterProfile;
        $store = $profile?->store;

        if (! $buyer || ! $store) {
            throw new \RuntimeException('The promoter has no store to receive this deal — onboarding is incomplete.');
        }

        $priceUgx = round((float) ($application->proposed_price_ugx ?: $opportunity->budget_max_ugx), 2);
        $priceCredits = (int) ($application->proposed_price_credits ?: $opportunity->budget_credits);
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
            'customer_notes' => "Opportunity award: {$opportunity->title}",
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $dealProduct->id,
            'product_name' => "Promotion deal — {$opportunity->title}",
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
            'promotable_type' => $opportunity->promotable_type,
            'promotable_id' => $opportunity->promotable_id,
            'opportunity_id' => $opportunity->id,
            'application_id' => $application->id,
        ]);

        $breakdown = app(PromotionSettlementService::class)->buildBreakdown($order, $dealProduct, $store->user);
        $item->forceFill([
            'product_snapshot' => [
                'opportunity' => ['id' => $opportunity->id, 'uuid' => $opportunity->uuid, 'title' => $opportunity->title],
                'promotion_settlement' => $breakdown,
            ],
        ])->save();

        if ($method === 'credits') {
            $buyer->spendCredits(
                $paidCredits,
                'promotion_award',
                "Opportunity award {$order->order_number}",
                ['order_id' => $order->id, 'opportunity_id' => $opportunity->id]
            );
        } else {
            $buyer->decrement('ugx_balance', $paidUgx);
        }

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
