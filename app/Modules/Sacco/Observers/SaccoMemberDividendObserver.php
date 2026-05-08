<?php

namespace App\Modules\Sacco\Observers;

use App\Models\Sacco\SaccoMemberDividend;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class SaccoMemberDividendObserver
{
    public function updated(SaccoMemberDividend $memberDividend): void
    {
        // Create feed item when individual member dividend is paid
        if ($memberDividend->isDirty('status') && $memberDividend->status === 'paid') {
            try {
                $member = $memberDividend->member;
                $user = $member?->user;
                if (! $user) {
                    return;
                }

                FeedItemService::create([
                    'type' => 'dividend_received',
                    'module' => 'sacco',
                    'title' => ($user->name ?? 'A member').' received SACCO dividend of UGX '.number_format($memberDividend->dividend_amount),
                    'actor_id' => $user->id,
                    'actor_type' => 'user',
                    'actor_name' => $user->name,
                    'actor_avatar_url' => $user->avatar_url ?? null,
                    'subject_type' => SaccoMemberDividend::class,
                    'subject_id' => $memberDividend->id,
                    'visibility' => 'members',
                    'extras' => [
                        'dividend_amount' => $memberDividend->dividend_amount,
                        'shares_amount' => $memberDividend->shares_amount,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('SaccoMemberDividendObserver: Failed to create feed item', ['id' => $memberDividend->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
