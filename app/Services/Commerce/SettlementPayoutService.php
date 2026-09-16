<?php

namespace App\Services\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves cleared earnings into the beneficiary's wallet.
 *
 * For store, event and promotion sales the ledger is the only record of what
 * a seller is owed. Settlements used to clear and stop there: the dashboard
 * showed "available" earnings that withdrawal (which reads ugx_balance) could
 * never reach. This pays each cleared settlement into the wallet — UGX to
 * ugx_balance, credits to the credit wallet — and marks it paid out against a
 * Payment row, so the money appears in wallet history and can be withdrawn
 * through the existing KYC-gated path.
 *
 * Music and contributions are paid elsewhere (ArtistRevenue, direct credit
 * awards) and mirror into the ledger, so they are never paid here.
 */
class SettlementPayoutService
{
    public const PAYMENT_TYPE = 'earnings_payout';

    public function __construct(private readonly SettlementService $ledger) {}

    /**
     * @return list<string>
     */
    public function walletVerticals(): array
    {
        return (array) config('commerce.wallet_payout_verticals', [
            Settlement::VERTICAL_STORE,
            Settlement::VERTICAL_EVENTS,
            Settlement::VERTICAL_PROMOTIONS,
        ]);
    }

    /**
     * Pay every cleared settlement in a wallet vertical. One failure never
     * blocks the rest.
     *
     * @return array{paid: int, failed: int}
     */
    public function payDue(): array
    {
        $paid = 0;
        $failed = 0;

        Settlement::query()
            ->cleared()
            ->whereIn('vertical', $this->walletVerticals())
            ->orderBy('id')
            ->chunkById(100, function ($settlements) use (&$paid, &$failed) {
                foreach ($settlements as $settlement) {
                    try {
                        if ($this->payToWallet($settlement)) {
                            $paid++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error('commerce.settlement.wallet_payout_failed', [
                            'settlement_id' => $settlement->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['paid' => $paid, 'failed' => $failed];
    }

    /**
     * Pay one settlement into its beneficiary's wallet. Returns false when
     * there was nothing to do (already paid, not cleared, wrong vertical).
     */
    public function payToWallet(Settlement $settlement): bool
    {
        if (! in_array($settlement->vertical, $this->walletVerticals(), true)) {
            return false;
        }

        return DB::transaction(function () use ($settlement): bool {
            // Lock and re-read: the hourly command and a manual run must not
            // both pay the same row.
            $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Settlement::STATUS_CLEARED) {
                return false;
            }

            // Refunds in these verticals do not all reverse the ledger, so the
            // sale is re-checked here: money for a refunded sale is never paid.
            $verdict = $this->sourceVerdict($locked);

            if ($verdict === 'reverse') {
                $this->ledger->reverse($locked, 'Source sale was refunded or cancelled before payout.');

                return false;
            }

            if ($verdict === 'hold') {
                return false;
            }

            $user = User::query()->whereKey($locked->beneficiary_user_id)->lockForUpdate()->first();

            if (! $user) {
                Log::warning('commerce.settlement.wallet_payout_no_beneficiary', ['settlement_id' => $locked->id]);

                return false;
            }

            $netUgx = round((float) $locked->net_ugx, 2);
            $netCredits = (int) $locked->net_credits;
            $reference = 'ERN-'.Str::upper(Str::random(12));
            $label = $this->describe($locked);

            if ($netUgx > 0) {
                $user->increment('ugx_balance', $netUgx);
            }

            if ($netCredits > 0) {
                $user->addCredits($netCredits, 'earnings_payout', $label, [
                    'settlement_id' => $locked->id,
                    'settlement_uuid' => $locked->uuid,
                    'reference' => $reference,
                ]);
            }

            $payment = new Payment([
                'user_id' => $user->id,
                'payment_type' => self::PAYMENT_TYPE,
                'payment_method' => 'wallet',
                'provider' => 'wallet',
                'payment_provider' => 'wallet',
                'currency' => 'UGX',
                'description' => $label,
                'payment_reference' => $reference,
                'transaction_reference' => $reference,
                'metadata' => [
                    'settlement_id' => $locked->id,
                    'settlement_uuid' => $locked->uuid,
                    'vertical' => $locked->vertical,
                    'kind' => $locked->kind,
                    'gross_ugx' => (float) $locked->gross_ugx,
                    'fee_ugx' => (float) $locked->fee_ugx,
                    'net_ugx' => $netUgx,
                    'gross_credits' => (int) $locked->gross_credits,
                    'fee_credits' => (int) $locked->fee_credits,
                    'net_credits' => $netCredits,
                ],
            ]);
            $payment->forceFill([
                'amount' => $netUgx,
                'status' => Payment::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            $this->ledger->markPaidOut([$locked], $payment);

            return true;
        });
    }

    /**
     * 'pay' when the sale still stands, 'reverse' when it was refunded or
     * cancelled, 'hold' when a person has to look (missing source, open
     * dispute, cancelled event).
     */
    private function sourceVerdict(Settlement $settlement): string
    {
        $source = $settlement->source;

        if (! $source) {
            Log::warning('commerce.settlement.wallet_payout_source_missing', ['settlement_id' => $settlement->id]);

            return 'hold';
        }

        if ($source instanceof Payment) {
            if ($source->status !== Payment::STATUS_COMPLETED) {
                return 'reverse';
            }

            // Ticket money waits for the show: an event moved or cancelled
            // after its settlement cleared must not already have paid out.
            $eventId = data_get($settlement->metadata, 'event_id');
            if ($eventId) {
                $event = Event::query()->find($eventId);
                $endsAt = $event?->ends_at ?? $event?->starts_at;

                if (! $event || $event->status === 'cancelled' || ($endsAt && $endsAt->isFuture())) {
                    return 'hold';
                }
            }

            return 'pay';
        }

        $order = match (true) {
            $source instanceof Order => $source,
            $source instanceof OrderItem => $source->order,
            default => null,
        };

        if ($order) {
            if ($order->payment_status === Order::PAYMENT_REFUNDED
                || in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
                return 'reverse';
            }
        }

        if ($source instanceof OrderItem && app(PromotionSettlementService::class)->hasOpenDispute($source)) {
            return 'hold';
        }

        return 'pay';
    }

    private function describe(Settlement $settlement): string
    {
        return match ($settlement->vertical) {
            Settlement::VERTICAL_PROMOTIONS => 'Promotion earnings',
            Settlement::VERTICAL_EVENTS => 'Ticket sales earnings',
            Settlement::VERTICAL_STORE => 'Store sales earnings',
            default => 'Earnings',
        }." (settlement #{$settlement->id})";
    }
}
