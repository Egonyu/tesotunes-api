<?php

namespace App\Modules\Sacco\Observers;

use App\Models\Sacco\SaccoDividend;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class SaccoDividendObserver
{
    public function created(SaccoDividend $dividend): void
    {
        try {
            // Dividend declaration is a system/admin action — use higher prestige
            FeedItemService::create([
                'type' => 'dividend_received',
                'module' => 'sacco',
                'title' => 'SACCO dividend declared for '.$dividend->dividend_year,
                'body' => 'Dividend rate: '.number_format($dividend->dividend_rate, 2).'% on total profit of UGX '.number_format($dividend->total_profit),
                'actor_id' => 0,
                'actor_type' => 'system',
                'actor_name' => 'TesoTunes SACCO',
                'subject_type' => SaccoDividend::class,
                'subject_id' => $dividend->id,
                'visibility' => 'members',
                'is_prestige' => true,
                'extras' => [
                    'dividend_year' => $dividend->dividend_year,
                    'dividend_rate' => $dividend->dividend_rate,
                    'total_profit' => $dividend->total_profit,
                    'payment_date' => $dividend->payment_date?->toDateString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('SaccoDividendObserver: Failed to create feed item', ['dividend_id' => $dividend->id, 'error' => $e->getMessage()]);
        }
    }

    public function updated(SaccoDividend $dividend): void
    {
        // When dividend is paid out, create another feed item
        if ($dividend->isDirty('status') && $dividend->status === 'paid') {
            try {
                FeedItemService::create([
                    'type' => 'dividend_received',
                    'module' => 'sacco',
                    'title' => 'SACCO dividends for '.$dividend->dividend_year.' have been paid out!',
                    'actor_id' => 0,
                    'actor_type' => 'system',
                    'actor_name' => 'TesoTunes SACCO',
                    'subject_type' => SaccoDividend::class,
                    'subject_id' => $dividend->id,
                    'visibility' => 'members',
                    'is_prestige' => true,
                    'has_celebration' => true,
                ]);
            } catch (\Exception $e) {
                Log::error('SaccoDividendObserver: Failed to create payout feed item', ['dividend_id' => $dividend->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
