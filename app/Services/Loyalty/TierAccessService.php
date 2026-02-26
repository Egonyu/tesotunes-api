<?php

namespace App\Services\Loyalty;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TierAccessService
{
    protected array $tierLevels;

    public function __construct()
    {
        $this->tierLevels = config('loyalty.tier_levels', [
            'bronze'   => 1,
            'silver'   => 2,
            'gold'     => 3,
            'platinum' => 4,
        ]);
    }

    /**
     * Check if a user can access a tier-restricted event.
     */
    public function canAccessEvent(User $user, Event $event): array
    {
        if (!$event->requiresLoyaltyTier()) {
            return ['can_access' => true];
        }

        $membership = $this->getActiveMembership($user, $event->loyalty_card_id);

        if (!$membership) {
            return [
                'can_access'    => false,
                'reason'        => 'No active membership for this loyalty card.',
                'required_tier' => $event->required_loyalty_tier,
            ];
        }

        if (!$membership->meetsOrExceedsTier($event->required_loyalty_tier)) {
            return [
                'can_access'    => false,
                'reason'        => 'Your tier does not meet the requirement.',
                'current_tier'  => $membership->tier,
                'required_tier' => $event->required_loyalty_tier,
            ];
        }

        return [
            'can_access' => true,
            'membership' => $membership,
        ];
    }

    /**
     * Check if a user can purchase a tier-restricted ticket.
     */
    public function canPurchaseTicket(User $user, EventTicket $ticket): array
    {
        if (!$ticket->requiresLoyaltyTier()) {
            return ['can_access' => true, 'discount' => null];
        }

        $event = $ticket->event;
        $eventAccess = $this->canAccessEvent($user, $event);

        if (!$eventAccess['can_access']) {
            return $eventAccess;
        }

        $membership = $eventAccess['membership'] ?? $this->getActiveMembership($user, $event->loyalty_card_id);
        $discount = $this->calculateTicketDiscount($ticket, $membership);
        $earlyAccess = $this->hasEarlyAccess($user, $ticket);

        return [
            'can_access'       => true,
            'has_early_access' => $earlyAccess['has_early_access'] ?? false,
            'discount'         => $discount,
        ];
    }

    /**
     * Determine early-access status for a ticket.
     */
    public function hasEarlyAccess(User $user, EventTicket $ticket): array
    {
        $event = $ticket->event;
        if (!$event->loyalty_card_id) {
            return ['has_early_access' => false];
        }

        $membership = $this->getActiveMembership($user, $event->loyalty_card_id);

        if (!$membership) {
            return ['has_early_access' => false];
        }

        $earlyAccessHours = $ticket->tier_early_access_hours ?? 0;
        if ($earlyAccessHours <= 0) {
            return ['has_early_access' => false];
        }

        $publicSaleStart = $ticket->sale_starts_at;
        if (!$publicSaleStart) {
            return ['has_early_access' => false];
        }

        $memberSaleStart = $publicSaleStart->copy()->subHours($earlyAccessHours);

        return [
            'has_early_access'        => true,
            'early_access_starts_at'  => $memberSaleStart->toIso8601String(),
            'public_sale_starts_at'   => $publicSaleStart->toIso8601String(),
            'hours_advantage'         => $earlyAccessHours,
        ];
    }

    /**
     * Scope events to only those accessible by the given user.
     */
    public function scopeAccessibleEvents(Builder $query, User $user): Builder
    {
        $activeMemberships = LoyaltyCardMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->select('loyalty_card_id', 'tier')
            ->get();

        return $query->where(function (Builder $q) use ($activeMemberships) {
            // Events with no tier requirement are always accessible
            $q->whereNull('required_loyalty_tier');

            if ($activeMemberships->isNotEmpty()) {
                $q->orWhere(function (Builder $sub) use ($activeMemberships) {
                    foreach ($activeMemberships as $m) {
                        $allowedTiers = $this->tiersAtOrBelow($m->tier);
                        $sub->orWhere(function (Builder $inner) use ($m, $allowedTiers) {
                            $inner->where('loyalty_card_id', $m->loyalty_card_id)
                                ->whereIn('required_loyalty_tier', $allowedTiers);
                        });
                    }
                });
            }
        });
    }

    /**
     * Get user's highest active tier for a specific loyalty card.
     */
    public function getUserTierForCard(User $user, int $loyaltyCardId): ?string
    {
        $membership = $this->getActiveMembership($user, $loyaltyCardId);

        return $membership?->tier;
    }

    // ── Private helpers ───────────────────────────────────────────

    private function getActiveMembership(User $user, ?int $loyaltyCardId): ?LoyaltyCardMember
    {
        if (!$loyaltyCardId) {
            return null;
        }

        return LoyaltyCardMember::where('user_id', $user->id)
            ->where('loyalty_card_id', $loyaltyCardId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();
    }

    private function calculateTicketDiscount(EventTicket $ticket, ?LoyaltyCardMember $membership): ?array
    {
        if (!$membership) {
            return null;
        }

        $discounts = $ticket->tier_discounts;
        if (empty($discounts)) {
            return null;
        }

        $discountPercentage = $discounts[$membership->tier] ?? 0;
        if ($discountPercentage <= 0) {
            return null;
        }

        $originalPrice = $ticket->price_ugx ?? $ticket->price ?? 0;
        $discountedPrice = round($originalPrice * (1 - $discountPercentage / 100));

        return [
            'percentage'       => $discountPercentage,
            'original_price'   => $originalPrice,
            'discounted_price' => $discountedPrice,
            'savings'          => $originalPrice - $discountedPrice,
        ];
    }

    /**
     * Return all tier names whose level is ≤ the given tier.
     */
    private function tiersAtOrBelow(string $tier): array
    {
        $level = $this->tierLevels[$tier] ?? 0;

        return collect($this->tierLevels)
            ->filter(fn ($l) => $l <= $level)
            ->keys()
            ->values()
            ->toArray();
    }
}
