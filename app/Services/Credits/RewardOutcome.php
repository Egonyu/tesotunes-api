<?php

namespace App\Services\Credits;

use App\Models\CreditTransaction;

/**
 * What happened when the platform tried to reward somebody.
 *
 * A bare null told a caller nothing: an activity that pays no credits, a user
 * who has hit today's ceiling, and an award that genuinely failed all looked
 * identical. Only the last of those is a problem, and the difference decides
 * whether anyone should be told.
 */
readonly class RewardOutcome
{
    private function __construct(
        public string $status,
        public float $credits = 0,
        public ?CreditTransaction $transaction = null,
        public ?string $reason = null,
    ) {}

    /** Credits were awarded. */
    public static function awarded(CreditTransaction $transaction, float $credits): self
    {
        return new self('awarded', $credits, $transaction);
    }

    /** No rate is configured for this activity, so it pays nothing. Not a fault. */
    public static function notRewarded(string $activity): self
    {
        return new self('not_rewarded', reason: "No live rate for '{$activity}'.");
    }

    /** The rate exists but is switched off or outside its campaign window. */
    public static function inactive(string $activity): self
    {
        return new self('inactive', reason: "The rate for '{$activity}' is not live right now.");
    }

    /** The user has earned all they may from this activity today. */
    public static function dailyLimitReached(float $limit): self
    {
        return new self('daily_limit', reason: "Daily limit of {$limit} credits reached.");
    }

    /** Too soon since the last award of this kind. */
    public static function cooldown(int $minutes): self
    {
        return new self('cooldown', reason: "Available again in {$minutes} minute(s).");
    }

    /** The user has taken this reward as many times as they ever can. */
    public static function lifetimeCapReached(int $cap): self
    {
        return new self('lifetime_cap', reason: "Already claimed the maximum of {$cap}.");
    }

    /** The award was attempted and did not land — a CreditIssue now exists. */
    public static function failed(string $reason): self
    {
        return new self('failed', reason: $reason);
    }

    public function wasAwarded(): bool
    {
        return $this->status === 'awarded';
    }

    /** True when nothing was paid because a rule said so, rather than a fault. */
    public function wasWithheld(): bool
    {
        return in_array($this->status, ['not_rewarded', 'inactive', 'daily_limit', 'cooldown', 'lifetime_cap'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'credits' => $this->credits,
            'reason' => $this->reason,
        ];
    }
}
