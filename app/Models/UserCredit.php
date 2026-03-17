<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserCredit extends Model
{
    protected $table = 'user_credits';

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'user_id', 'user_id');
    }

    public function getAvailableCreditsAttribute(): float
    {
        return (float) ($this->balance ?? 0);
    }

    public function getEarnedCreditsAttribute(): float
    {
        return (float) $this->transactions()
            ->whereIn('type', [CreditTransaction::TYPE_EARN, CreditTransaction::TYPE_EARNED, CreditTransaction::TYPE_BONUS])
            ->sum('amount');
    }

    public function getSpentCreditsAttribute(): float
    {
        return (float) $this->transactions()
            ->whereIn('type', [CreditTransaction::TYPE_SPEND, CreditTransaction::TYPE_SPENT, CreditTransaction::TYPE_PURCHASE])
            ->sum('amount');
    }

    public function getCreditsEarnedTodayAttribute(): float
    {
        return (float) $this->transactions()
            ->whereIn('type', [CreditTransaction::TYPE_EARN, CreditTransaction::TYPE_EARNED, CreditTransaction::TYPE_BONUS])
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    public function getCreditsSpentTodayAttribute(): float
    {
        return (float) $this->transactions()
            ->whereIn('type', [CreditTransaction::TYPE_SPEND, CreditTransaction::TYPE_SPENT, CreditTransaction::TYPE_PURCHASE])
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    public function getTotalLifetimeCreditsAttribute(): float
    {
        return $this->earned_credits;
    }

    public function hasMinimumBalance(float $amount): bool
    {
        return $this->available_credits >= $amount;
    }

    public function addCredits(
        float $amount,
        ?string $source = null,
        ?string $description = null,
        array $metadata = []
    ): CreditTransaction {
        return DB::transaction(function () use ($amount, $source, $description, $metadata) {
            $wallet = self::query()->lockForUpdate()->findOrFail($this->id);
            $newBalance = (float) $wallet->balance + $amount;

            $wallet->update([
                'balance' => $newBalance,
            ]);

            return CreditTransaction::create([
                'user_id' => $wallet->user_id,
                'type' => CreditTransaction::TYPE_EARNED,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => $source,
                'description' => $description,
                'reference' => $metadata['reference'] ?? 'cr_'.Str::upper(Str::random(12)),
                'referenceable_type' => self::class,
                'referenceable_id' => $wallet->id,
            ]);
        });
    }

    public function spendCredits(
        float $amount,
        ?string $source = null,
        ?string $description = null,
        array $metadata = []
    ): ?CreditTransaction {
        if (! $this->hasMinimumBalance($amount)) {
            return null;
        }

        return DB::transaction(function () use ($amount, $source, $description, $metadata) {
            $wallet = self::query()->lockForUpdate()->findOrFail($this->id);

            if ((float) $wallet->balance < $amount) {
                return null;
            }

            $newBalance = (float) $wallet->balance - $amount;

            $wallet->update([
                'balance' => $newBalance,
            ]);

            return CreditTransaction::create([
                'user_id' => $wallet->user_id,
                'type' => CreditTransaction::TYPE_SPENT,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => $source,
                'description' => $description,
                'reference' => $metadata['reference'] ?? 'cr_'.Str::upper(Str::random(12)),
                'referenceable_type' => self::class,
                'referenceable_id' => $wallet->id,
            ]);
        });
    }

    public function transferCredits(User $recipient, float $amount, string $description = 'Credit transfer'): ?array
    {
        if (! $this->hasMinimumBalance($amount)) {
            return null;
        }

        return DB::transaction(function () use ($recipient, $amount, $description) {
            $senderWallet = self::query()->lockForUpdate()->findOrFail($this->id);
            $receiverWallet = $recipient->creditWallet()->firstOrCreate(
                ['user_id' => $recipient->id],
                ['balance' => 0, 'currency' => 'credits']
            );
            $receiverWallet = self::query()->lockForUpdate()->findOrFail($receiverWallet->id);

            if ((float) $senderWallet->balance < $amount) {
                return null;
            }

            $senderBalance = (float) $senderWallet->balance - $amount;
            $receiverBalance = (float) $receiverWallet->balance + $amount;
            $reference = 'trx_'.Str::upper(Str::random(12));

            $senderWallet->update(['balance' => $senderBalance]);
            $receiverWallet->update(['balance' => $receiverBalance]);

            return [
                'sender_transaction' => CreditTransaction::create([
                    'user_id' => $senderWallet->user_id,
                    'type' => CreditTransaction::TYPE_SPENT,
                    'amount' => $amount,
                    'balance_after' => $senderBalance,
                    'source' => 'transfer_out',
                    'description' => $description,
                    'reference' => $reference,
                    'referenceable_type' => User::class,
                    'referenceable_id' => $recipient->id,
                ]),
                'recipient_transaction' => CreditTransaction::create([
                    'user_id' => $receiverWallet->user_id,
                    'type' => CreditTransaction::TYPE_EARNED,
                    'amount' => $amount,
                    'balance_after' => $receiverBalance,
                    'source' => 'transfer_in',
                    'description' => $description,
                    'reference' => $reference,
                    'referenceable_type' => User::class,
                    'referenceable_id' => $senderWallet->user_id,
                ]),
            ];
        });
    }
}
