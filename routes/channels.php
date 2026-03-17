<?php

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;

Broadcast::channel('user.{id}', function ($user, int $id): bool {
    return (int) $user->id === $id;
});

Broadcast::channel('module.{module}', function ($user, string $module): bool {
    return $user !== null && $module !== '';
});

Broadcast::channel('fan-club.{cardId}', function ($user, int $cardId): bool {
    if (! Schema::hasTable('loyalty_cards') || ! Schema::hasTable('loyalty_card_members')) {
        return false;
    }

    $card = LoyaltyCard::query()->with('artist')->find($cardId);

    if (! $card) {
        return false;
    }

    if ($user->isAdmin()) {
        return true;
    }

    if ((int) optional($card->artist)->user_id === (int) $user->id) {
        return true;
    }

    return LoyaltyCardMember::query()
        ->where('loyalty_card_id', $cardId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();
});
