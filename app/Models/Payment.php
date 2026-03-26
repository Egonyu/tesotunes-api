<?php

namespace App\Models;

use App\Services\Settings\ArtistSettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    // Only allow safe properties for mass assignment
    protected $fillable = [
        'user_id',
        'payable_type',
        'payable_id',
        'song_id',
        'subscription_plan_id',
        'payment_type',
        'payment_method',
        'provider',
        'payment_provider',
        'phone_number',
        'email',
        'description',
        'notes',
        'currency',
        'transaction_reference',
        'payment_reference',
        'provider_transaction_id',
        'provider_reference',
        'provider_response',
        'exchange_rate',
        'payment_data',
        'payment_details',
        'metadata',
        'failure_reason',
        'refund_reason',
    ];

    // Protect sensitive financial fields - forceFill() bypasses this
    protected $guarded = [
        'id',
        'uuid',
        'amount',
        'amount_usd',
        'refund_amount',
        'status',
        'transaction_id',
        'initiated_at',
        'completed_at',
        'failed_at',
        'refunded_at',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate UUID if not provided
        static::creating(function ($payment) {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
        });
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'exchange_rate' => 'decimal:2',
        'payment_data' => 'array',
        'payment_details' => 'array',
        'metadata' => 'array',
        'provider_response' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    // Payment statuses
    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_REFUNDED = 'refunded';

    // Payment methods
    const METHOD_MOBILE_MONEY = 'mobile_money'; // legacy, kept for existing records

    const METHOD_ZENGAPAY = 'zengapay';

    // Payment provider — ZengaPay only
    const PROVIDER_ZENGAPAY = 'zengapay';

    // Mobile money providers (used by MobileMoneyService)
    const PROVIDER_MTN = 'mtn';

    const PROVIDER_AIRTEL = 'airtel';

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function userSubscription()
    {
        return $this->hasOne(UserSubscription::class, 'payment_id');
    }

    public function saccoTransactions()
    {
        return $this->morphMany(\App\Models\Sacco\SaccoTransaction::class, 'source')
            ->where('transaction_type', 'deposit');
    }

    public function issues()
    {
        return $this->hasMany(PaymentIssue::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeMobileMoney($query)
    {
        return $query->where('payment_method', self::METHOD_MOBILE_MONEY);
    }

    public function scopeByProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    // Accessors
    public function getFormattedAmountAttribute(): string
    {
        return 'UGX '.number_format($this->amount, 0);
    }

    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_REFUNDED => 'Refunded',
            default => 'Unknown'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'text-yellow-400',
            self::STATUS_PROCESSING => 'text-blue-400',
            self::STATUS_COMPLETED => 'text-green-400',
            self::STATUS_FAILED => 'text-red-400',
            self::STATUS_CANCELLED => 'text-gray-400',
            self::STATUS_REFUNDED => 'text-purple-400',
            default => 'text-gray-400'
        };
    }

    public function getProviderNameAttribute(): string
    {
        $provider = $this->payment_provider ?? $this->provider;

        return match ($provider) {
            self::PROVIDER_ZENGAPAY, 'zengapay' => 'ZengaPay',
            // Legacy providers (for historical records)
            'mtn', 'mtn_mobile_money' => 'MTN Mobile Money (legacy)',
            'airtel', 'airtel_money' => 'Airtel Money (legacy)',
            default => ucfirst($provider ?? 'ZengaPay')
        };
    }

    // Alias for backwards compatibility
    public function getExternalTransactionIdAttribute(): ?string
    {
        return $this->provider_transaction_id;
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function canBeRefunded(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    // Status management
    public function markAsProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'initiated_at' => now(),
        ])->save();
    }

    public function markAsCompleted(array $data = []): void
    {
        $updateData = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ];

        if (isset($data['external_transaction_id'])) {
            $updateData['provider_transaction_id'] = $data['external_transaction_id'];
        }

        if (isset($data['provider_reference'])) {
            $updateData['provider_reference'] = $data['provider_reference'];

            if (blank($this->payment_reference)) {
                $updateData['payment_reference'] = $data['provider_reference'];
            }
        }

        if (isset($data['payment_data'])) {
            $updateData['payment_data'] = array_merge($this->payment_data ?? [], $data['payment_data']);
        }

        $this->forceFill($updateData)->save();
        $this->settleLedgerIfApplicable();
        $this->activateSubscriptionIfApplicable();

        // Auto-confirm attendee if this is an event payment
        if ($this->payable_type === EventAttendee::class && $this->payable) {
            $this->payable->confirm($this->provider_reference ?? $this->provider_transaction_id);
        }

        if ($this->payment_type === 'ticket_purchase') {
            app(\App\Services\Events\EventTicketingService::class)->settlePendingOrderPayment($this);
            app(\App\Services\Events\EventPayoutLedgerService::class)->markPaymentReady($this);
        }

        // Complete auto-renewal if this is a subscription renewal payment
        $this->completeAutoRenewalIfApplicable();
    }

    public function markAsFailed(?string $reason = null, array $data = []): void
    {
        $updateData = [
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ];

        if (isset($data['payment_data'])) {
            $updateData['payment_data'] = array_merge($this->payment_data ?? [], $data['payment_data']);
        }

        $this->forceFill($updateData)->save();
        $this->refundWithdrawalIfApplicable();

        if ($this->payment_type === 'ticket_purchase') {
            app(\App\Services\Events\EventTicketingService::class)->failPendingOrderPayment($this, $reason);
            app(\App\Services\Events\EventPayoutLedgerService::class)->markPaymentFailed($this, $reason);
        }

        // Expire subscription if auto-renewal payment failed
        $this->failAutoRenewalIfApplicable($reason);
    }

    public function markAsCancelled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'failed_at' => now(),
        ])->save();
        $this->refundWithdrawalIfApplicable();

        if ($this->payment_type === 'ticket_purchase') {
            app(\App\Services\Events\EventTicketingService::class)->failPendingOrderPayment($this, 'Payment cancelled');
            app(\App\Services\Events\EventPayoutLedgerService::class)->markPaymentFailed($this, 'Payment cancelled');
        }
    }

    public function markAsRefunded(): void
    {
        $this->forceFill([
            'status' => self::STATUS_REFUNDED,
            'refunded_at' => now(),
        ])->save();
    }

    /**
     * Complete subscription auto-renewal after successful payment webhook.
     */
    protected function completeAutoRenewalIfApplicable(): void
    {
        $metadata = $this->metadata ?? [];

        if (empty($metadata['auto_renewal']) || empty($metadata['subscription_id'])) {
            return;
        }

        $subscription = UserSubscription::find($metadata['subscription_id']);

        if (! $subscription) {
            \Illuminate\Support\Facades\Log::warning('Auto-renewal: subscription not found', [
                'payment_id' => $this->id,
                'subscription_id' => $metadata['subscription_id'],
            ]);

            return;
        }

        $plan = $subscription->subscriptionPlan;
        $durationDays = $plan ? $plan->duration_days : 30;

        // Extend from now (not from old expires_at, since it's already past)
        $subscription->update([
            'status' => 'active',
            'expires_at' => now()->addDays($durationDays),
            'extended_at' => now(),
            'extension_reason' => 'auto_renewal',
            'amount_paid' => $this->amount,
            'transaction_reference' => $this->payment_reference,
            'metadata' => array_merge($subscription->metadata ?? [], [
                'last_renewal_at' => now()->toIso8601String(),
                'last_renewal_payment_id' => $this->id,
            ]),
        ]);

        // Notify user
        if ($subscription->user && $plan) {
            $subscription->user->notify(
                new \App\Notifications\SubscriptionNotification(
                    \App\Notifications\SubscriptionNotification::RENEWED,
                    $plan->name
                )
            );
        }

        \Illuminate\Support\Facades\Log::info('Auto-renewal completed', [
            'payment_id' => $this->id,
            'subscription_id' => $subscription->id,
            'new_expires_at' => $subscription->fresh()->expires_at,
        ]);
    }

    /**
     * Expire subscription when auto-renewal payment fails.
     */
    protected function failAutoRenewalIfApplicable(?string $reason = null): void
    {
        $metadata = $this->metadata ?? [];

        if (empty($metadata['auto_renewal']) || empty($metadata['subscription_id'])) {
            return;
        }

        $subscription = UserSubscription::find($metadata['subscription_id']);

        if (! $subscription || $subscription->status === 'expired') {
            return;
        }

        $plan = $subscription->subscriptionPlan;

        $subscription->update([
            'status' => 'expired',
            'auto_renew' => false,
            'metadata' => array_merge($subscription->metadata ?? [], [
                'renewal_failed_at' => now()->toIso8601String(),
                'renewal_failure_reason' => $reason ?? 'Payment failed',
            ]),
        ]);

        if ($subscription->user && $plan) {
            $subscription->user->notify(
                new \App\Notifications\SubscriptionNotification(
                    \App\Notifications\SubscriptionNotification::PAYMENT_FAILED,
                    $plan->name,
                    ['reason' => $reason ?? 'Payment failed']
                )
            );
        }

        \Illuminate\Support\Facades\Log::info('Auto-renewal failed — subscription expired', [
            'payment_id' => $this->id,
            'subscription_id' => $subscription->id,
            'reason' => $reason,
        ]);
    }

    // Generate transaction ID
    public static function generateTransactionId(): string
    {
        return 'PAY_'.strtoupper(uniqid()).'_'.time();
    }

    // Create payment for attendee
    public static function createForAttendee(EventAttendee $attendee, array $paymentData): self
    {
        $payment = new self([
            'user_id' => $attendee->user_id,
            'payable_type' => EventAttendee::class,
            'payable_id' => $attendee->id,
            'payment_method' => $paymentData['payment_method'],
            'provider' => $paymentData['provider'] ?? null,
            'phone_number' => $paymentData['phone_number'] ?? null,
        ]);

        // Set protected attributes individually
        $payment->amount = $attendee->amount_paid;
        $payment->currency = 'UGX';
        $payment->status = self::STATUS_PENDING;
        $payment->transaction_id = self::generateTransactionId();
        $payment->payment_data = [
            'event_id' => $attendee->event_id,
            'ticket_type' => $attendee->ticket?->name,
            'quantity' => $attendee->quantity,
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
        ];

        $payment->save();

        return $payment;
    }

    // SACCO Integration - Convert completed payment to SACCO deposit
    public function canDepositToSacco(): bool
    {
        return $this->isCompleted()
            && $this->user?->isSaccoMember()
            && $this->amount > 0;
    }

    public function depositToSacco(string $accountType = 'savings'): ?\App\Models\Sacco\SaccoTransaction
    {
        if (! $this->canDepositToSacco()) {
            return null;
        }

        $member = $this->user->saccoMember;
        $account = $member->accounts()->where('account_type', $accountType)->first();

        if (! $account) {
            // Auto-create account if doesn't exist
            $account = \App\Models\Sacco\SaccoAccount::create([
                'member_id' => $member->id,
                'account_type' => $accountType,
            ]);
        }

        $balanceBefore = $account->balance;
        $account->increment('balance', $this->amount);
        $account->increment('available_balance', $this->amount);

        return \App\Models\Sacco\SaccoTransaction::create([
            'account_id' => $account->id,
            'member_id' => $member->id,
            'transaction_type' => 'deposit',
            'transaction_reference' => 'PAY-'.$this->transaction_id,
            'amount' => $this->amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $account->fresh()->balance,
            'description' => 'Mobile Money deposit via '.$this->provider_name,
            'notes' => 'Auto-deposit from payment #'.$this->id,
            'processed_by' => $this->user_id,
        ]);
    }

    /**
     * Get refund reason from notes
     */
    public function getRefundReasonAttribute()
    {
        if ($this->status === 'refunded' && $this->notes) {
            // Extract refund reason from notes (format: "Refund: reason | Amount: 50000")
            if (str_contains($this->notes, ' | Amount:')) {
                return trim(str_replace(['Refund: ', ' | Amount:'.$this->refund_amount], '', explode(' | Amount:', $this->notes)[0]));
            }

            return str_replace('Refund: ', '', $this->notes);
        }

        return null;
    }

    /**
     * Get refund amount from notes
     */
    public function getRefundAmountAttribute()
    {
        if ($this->status === 'refunded' && $this->notes && str_contains($this->notes, ' | Amount:')) {
            // Extract amount from notes (format: "Refund: reason | Amount: 50000")
            preg_match('/Amount:\s*([0-9.]+)/', $this->notes, $matches);

            return isset($matches[1]) ? (float) $matches[1] : $this->amount;
        }

        return $this->amount ?? null;
    }

    protected function settleLedgerIfApplicable(): void
    {
        $paymentData = $this->payment_data ?? [];
        if (! empty($paymentData['ledger_settled_at'])) {
            return;
        }

        $user = $this->user;
        if (! $user) {
            return;
        }

        if ($this->payment_type === 'wallet_topup') {
            $user->increment('ugx_balance', (float) $this->amount);
            $this->stampPaymentData([
                'ledger_settled_at' => now()->toIso8601String(),
                'wallet_credited' => true,
            ]);

            return;
        }

        if ($this->payment_type === 'credits_purchase') {
            $creditsAmount = (int) data_get(
                $this->metadata,
                'credits_amount',
                round((float) $this->amount * max(1, (float) Setting::get('credits_per_ugx', config('store.currencies.credits.conversion_rate', 1))))
            );

            $wallet = $user->creditWallet()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'currency' => 'credits']
            );

            $wallet->addCredits(
                $creditsAmount,
                'mobile_money_purchase',
                "Purchased {$creditsAmount} credits via ZengaPay",
                ['reference' => $this->payment_reference]
            );

            $this->stampPaymentData([
                'ledger_settled_at' => now()->toIso8601String(),
                'credits_settled' => $creditsAmount,
            ]);

            return;
        }

        if ($this->payment_type === 'purchase') {
            $songId = (int) ($this->song_id
                ?? data_get($this->metadata, 'song_id')
                ?? ($this->payable_type === Song::class ? $this->payable_id : 0));

            if ($songId <= 0) {
                return;
            }

            $song = Song::query()->find($songId);

            if (! $song) {
                return;
            }

            $existingPurchase = SongPurchase::query()
                ->where('user_id', $this->user_id)
                ->where('song_id', $song->id)
                ->first();

            $purchase = $existingPurchase;

            if (! $purchase) {
                $purchase = SongPurchase::create([
                    'user_id' => $this->user_id,
                    'song_id' => $song->id,
                    'amount_paid' => (float) $this->amount,
                    'currency' => $this->currency ?: ($song->currency ?? 'UGX'),
                    'payment_method' => $this->payment_method ?: 'zengapay',
                    'transaction_id' => $this->transaction_id ?: self::generateTransactionId(),
                    'purchased_at' => now(),
                ]);
            }

            $paymentData = $this->payment_data ?? [];
            if (empty($paymentData['purchase_revenue_recorded_at'])) {
                $artistSharePct = (float) data_get($this->metadata, 'distribution.artist_percentage', -1);
                if ($artistSharePct < 0 || $artistSharePct > 100) {
                    $artistSharePct = max(0, min(100, (float) app(ArtistSettingsService::class)->getRevenueShare()));
                }

                $platformSharePct = 100 - $artistSharePct;
                $artistAmount = round((float) $this->amount * ($artistSharePct / 100), 2);
                $platformAmount = round((float) $this->amount - $artistAmount, 2);

                ArtistRevenue::create([
                    'uuid' => (string) Str::uuid(),
                    'artist_id' => $song->artist_id,
                    'revenue_type' => ArtistRevenue::TYPE_DOWNLOAD,
                    'sourceable_type' => Song::class,
                    'sourceable_id' => $song->id,
                    'song_id' => $song->id,
                    'amount_ugx' => (float) $this->amount,
                    'amount_usd' => 0,
                    'platform_fee' => $platformAmount,
                    'net_amount' => $artistAmount,
                    'revenue_date' => now()->toDateString(),
                    'status' => ArtistRevenue::STATUS_CONFIRMED,
                    'notes' => 'Song purchase revenue split '.$artistSharePct.'/'.$platformSharePct.' payment#'.$this->id,
                ]);

                if ($song->artist && empty($paymentData['artist_wallet_credited_at'])) {
                    $song->artist->increment('earnings_balance', $artistAmount);
                    $song->artist->increment('total_revenue', $artistAmount);
                }

                $this->stampPaymentData([
                    'purchase_revenue_recorded_at' => now()->toIso8601String(),
                    'artist_wallet_credited_at' => now()->toIso8601String(),
                    'song_purchase_id' => $purchase->id,
                    'ledger_settled_at' => now()->toIso8601String(),
                ]);
            }
        }
    }

    protected function activateSubscriptionIfApplicable(): void
    {
        if ($this->payment_type !== 'subscription') {
            return;
        }

        if ($this->userSubscription) {
            return;
        }

        if (! $this->subscriptionPlan || ! $this->user) {
            return;
        }

        App::make(\App\Services\PaymentService::class)->activateSubscriptionFromPayment($this);
    }

    protected function refundWithdrawalIfApplicable(): void
    {
        if ($this->payment_type !== 'withdrawal') {
            return;
        }

        $paymentData = $this->payment_data ?? [];
        if (! empty($paymentData['withdrawal_refunded_at'])) {
            return;
        }

        $user = $this->user;
        if (! $user) {
            return;
        }

        $user->increment('ugx_balance', (float) $this->amount);
        $this->stampPaymentData([
            'withdrawal_refunded_at' => now()->toIso8601String(),
        ]);
    }

    protected function stampPaymentData(array $data): void
    {
        $this->forceFill([
            'payment_data' => array_merge($this->payment_data ?? [], $data),
        ])->save();
    }
}
